billing
=================

[![Build Status](https://travis-ci.org/infusephp/billing.png?branch=master)](https://travis-ci.org/infusephp/billing)
[![Coverage Status](https://coveralls.io/repos/infusephp/billing/badge.png)](https://coveralls.io/r/infusephp/billing)
[![Latest Stable Version](https://poser.pugx.org/infuse/billing/v/stable.png)](https://packagist.org/packages/infuse/billing)
[![Total Downloads](https://poser.pugx.org/infuse/billing/downloads.png)](https://packagist.org/packages/infuse/billing)
[![HHVM Status](http://hhvm.h4cc.de/badge/infuse/billing.svg)](http://hhvm.h4cc.de/package/infuse/billing)

Stripe billing module for Infuse Framework

## Installation

1. Install the package with [composer](http://getcomposer.org):

```
composer require infuse/billing
```

2. Add a billing section to your `config.php`:
```php
[
	'model' => 'App\Users\Models\\User',
	'emails' => [
		'trial_will_end' => true,
		'trial_ended' => true,
		'failed_payment' => true,
		'payment_receipt' => true,
		'subscription_canceled' => true
	],
	'defaultPlan' => 'default_plan',
	'plans' => [
		...
	]
]
```

And add the console command to run jobs to `modules.commands` in your app's configuration:
```php
'modules' => [
	// ...
	'commands' => [
		// ...
		'App\Billing\Console\SyncStripeSubscriptionsCommand',
		'App\Billing\Console\SyncStripeProfilesCommand'
	]
]
```

3. Add the following cron job to `cron.php`:
```php
[
    'module' => 'billing',
    'command' => 'sendTrialReminders',
    'minute' => 0,
    'expires' => 1800, // 30 minutes
]
```

4. (optional) Add an endpoint to your routing table to receive Stripe webhooks:

```php
'routes' => [
	// ...
	'POST /billing/webhook' => [
		'App\Billing\Controller',
		'webhook'
    ]
	// ...
]
```