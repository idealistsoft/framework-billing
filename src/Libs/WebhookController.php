<?php

namespace Infuse\Billing\Libs;

use ICanBoogie\Inflector;
use Infuse\Application;
use Infuse\HasApp;
use infuse\Request;
use infuse\Response;
use Stripe\Error\Base as StripeError;
use Stripe\Event;
use Stripe\Charge;
use Stripe\Invoice;
use Stripe\Stripe;

class WebhookController
{
    use HasApp;

    const ERROR_GENERIC = 'error';
    const ERROR_INVALID_EVENT = 'invalid_event';
    const ERROR_LIVEMODE_MISMATCH = 'livemode_mismatch';
    const ERROR_STRIPE_CONNECT_EVENT = 'stripe_connect_event';
    const ERROR_EVENT_NOT_SUPPORTED = 'event_not_supported';
    const ERROR_CUSTOMER_NOT_FOUND = 'customer_not_found';
    const SUCCESS = 'OK';

    /**
     * Route to handle an incoming webhook.
     *
     * @param Request  $req
     * @param Response $res
     */
    public function webhook(Request $req, Response $res)
    {
        $this->app['auth']->getCurrentUser()->promoteToSuperUser();

        $res->setBody($this->handle($req->request()));
    }

    /**
     * This function tells the controller to process the Stripe event.
     *
     * @return string output
     */
    public function handle(array $event)
    {
        if (!isset($event['id'])) {
            return self::ERROR_INVALID_EVENT;
        }

        // check that the livemode matches our development state
        $environment = $this->app['environment'];
        if (!($event['livemode'] && $environment === Application::ENV_PRODUCTION ||
            !$event['livemode'] && $environment !== Application::ENV_PRODUCTION)) {
            return self::ERROR_LIVEMODE_MISMATCH;
        }

        if (isset($event['user_id'])) {
            return self::ERROR_STRIPE_CONNECT_EVENT;
        }

        // grab up the API key
        Stripe::setApiKey($this->app['config']->get('stripe.secret'));

        try {
            // retreive the event, unless it is a deauth event
            // since those cannot be retrieved
            $validatedEvent = ($event['type'] == 'account.application.deauthorized') ?
                (object) $event :
                Event::retrieve($event['id']);

            // get the data attached to the event
            $eventData = $validatedEvent->data->object;

            // find out which user this event is for by cross-referencing the customer id
            $modelClass = $this->app['config']->get('billing.model');

            $stripeCustomerId = $eventData->customer;
            if (!$stripeCustomerId && $chargeId = $eventData->charge) {
                $charge = Charge::retrieve($chargeId);
                $stripeCustomerId = $charge->customer;
            }

            $member = $modelClass::where('stripe_customer', $stripeCustomerId)->first();

            if (!$member) {
                return self::ERROR_CUSTOMER_NOT_FOUND;
            }

            // determine handler by checking if the method exists
            // i.e customer.subscription.created -> handleCustomerSubscriptionCreated
            $inflector = Inflector::get();
            $method = str_replace('.', '_', $validatedEvent->type);
            $method = 'handle'.$inflector->camelize($method);
            if (!method_exists($this, $method)) {
                return self::ERROR_EVENT_NOT_SUPPORTED;
            }

            if ($this->$method($eventData, $member)) {
                return self::SUCCESS;
            }
        } catch (StripeError $e) {
            $this->app['logger']->error($e);
        }

        return self::ERROR_GENERIC;
    }
}
