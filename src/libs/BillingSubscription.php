<?php

namespace app\billing\libs;

use app\billing\models\BillableModel;
use App;

class BillingSubscription
{
    private $model;
    private $plan;
    private $app;

    public function __construct(BillableModel $model, $plan, App $app)
    {
        $this->model = $model;
        $this->plan = $plan;
        $this->app = $app;
    }

    /**
     * Returns the plan associated with this object.
     *
     * @return string
     */
    public function plan()
    {
        return $this->plan;
    }

    /**
     * Creates a new Stripe subscription. If a token is provided it will
     * become the new default source for the customer.
     *
     * @param string  $token   optional Stripe token to use for the plan
     * @param boolean $noTrial when true, immediately ends (skips) the trial period for the new subscription
     *
     * @return boolean
     */
    public function create($token = false, $noTrial = false)
    {
        // cannot create a subscription if there is already an
        // existing active/unpaid existing plan; must use change() instead
        if (empty($this->plan) || !in_array($this->status(), ['not_subscribed', 'canceled'])) {
            return false;
        }

        $customer = $this->model->stripeCustomer();

        if (!$customer) {
            return false;
        }

        $params = [
            'plan' => $this->plan, ];

        if ($token) {
            $params['source'] = $token;
        }

        if ($noTrial) {
            $params['trial_end'] = 'now';
        }

        try {
            $subscription = $customer->updateSubscription($params);

            // update the user's billing state
            if (in_array($subscription->status, ['active', 'trialing'])) {
                $this->model->grantAllPermissions();
                $this->model->set([
                    'plan' => $this->plan,
                    'past_due' => false,
                    'renews_next' => $subscription->current_period_end,
                    'trial_ends' => $subscription->trial_end,
                    'canceled' => false,
                ]);
                $this->model->enforcePermissions();

                return true;
            }
        } catch (\Exception $e) {
            $this->app[ 'errors' ]->push([
                'error' => 'stripe_error',
                'message' => $e->getMessage(), ]);
            $this->app[ 'logger' ]->debug($e);
        }

        return false;
    }

    /**
     * Changes the plan the member is subscribed to.
     *
     * @param string  $plan    stripe plan id
     * @param boolean $noTrial when true, immediately ends (skips) the trial period for the new subscription
     *
     * @return boolean result
     */
    public function change($plan, $noTrial = false)
    {
        if (empty($plan) || !in_array($this->status(), ['active', 'trialing', 'past_due', 'unpaid'])
            || $this->model->not_charged) {
            return false;
        }

        $customer = $this->model->stripeCustomer();

        if (!$customer) {
            return false;
        }

        $params = [
            'plan' => $plan,
            'prorate' => true,
        ];

        // maintain the same trial end date if there is one
        if ($noTrial) {
            $params['trial_end'] = 'now';
        } elseif ($this->trialing()) {
            $params['trial_end'] = $this->model->trial_ends;
        }

        try {
            $subscription = $customer->updateSubscription($params);

            // update the user's billing state
            if (in_array($subscription->status, ['active', 'trialing']) && $subscription->plan->id == $plan) {
                $this->model->grantAllPermissions();
                $this->model->set([
                    'plan' => $plan,
                    'past_due' => false,
                    'renews_next' => $subscription->current_period_end,
                    'trial_ends' => $subscription->trial_end,
                    'canceled' => false,
                ]);
                $this->model->enforcePermissions();

                $this->plan = $plan;

                return true;
            }
        } catch (\Exception $e) {
            $this->app[ 'errors' ]->push([ 'error' => 'stripe_error', 'message' => $e->getMessage() ]);
            $this->app[ 'logger' ]->debug($e);
        }

        return false;
    }

    /**
     * Cancels the subscription.
     *
     * @return boolean
     */
    public function cancel()
    {
        if (!$this->active()) {
            return false;
        }

        $customer = $this->model->stripeCustomer();

        if (!$customer) {
            return false;
        }

        try {
            $subscription = $customer->cancelSubscription();

            if ($subscription->status == 'canceled') {
                $this->model->grantAllPermissions();
                $this->model->set('canceled', true);
                $this->model->enforcePermissions();

                return true;
            }
        } catch (\Exception $e) {
            $this->app[ 'logger' ]->debug($e);
            $this->app[ 'errors' ]->push([ 'error' => 'stripe_error', 'message' => $e->getMessage() ]);
        }

        return false;
    }

    /**
     * Gets the status of the subscription.
     *
     * @return string one of not_subscribed, trialing, active, past_due, canceled, or unpaid
     */
    public function status()
    {
        if ($this->model->canceled) {
            return 'canceled';
        }

        // subscription plan must match model's bililng plan for it to
        // have a non-canceled status
        if (!$this->plan || $this->model->plan != $this->plan) {
            return 'not_subscribed';
        }

        // check if subscription is trialing
        if ($this->model->trial_ends > time()) {
            return 'trialing';
        }

        // flag to skip charging this model - unless
        // `trialing` or `canceled` the subscription is always active
        if ($this->model->not_charged) {
            return 'active';
        }

        // check if the subscription is active or trialing
        if ($this->model->renews_next > time()) {
            return 'active';
        }

        // the subscription is past due when its status has been changed
        // to past_due on stripe
        if ($this->model->past_due) {
            return 'past_due';
        }

        return 'unpaid';
    }

    /**
     * Checks if the model's subscription is active.
     *
     * @return boolean
     */
    public function active()
    {
        return in_array($this->status(),
            [ 'active', 'trialing', 'past_due' ]);
    }

    /**
     * Checks if the model's subscription is canceled.
     *
     * @return boolean
     */
    public function canceled()
    {
        return $this->status() == 'canceled';
    }

    /**
     * Checks if the model's subscription is in a trial period.
     *
     * @return boolean
     */
    public function trialing()
    {
        return $this->status() == 'trialing';
    }
}
