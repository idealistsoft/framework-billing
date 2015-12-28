<?php

namespace App\Billing;

use App\Billing\Libs\StripeWebhook;

class Controller extends StripeWebhook
{
    public static $properties = [
        'models' => [
            'BillingHistory',
        ],
    ];

    public static $scaffoldAdmin;

    public function sendTrialReminders()
    {
        $modelClass = $this->app['config']->get('billing.model');

        return $modelClass::sendTrialReminders();
    }
}
