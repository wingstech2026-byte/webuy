<?php

use Illuminate\Support\Facades\Schedule;

// Run `php artisan schedule:run` every minute from cron (see README).
Schedule::command('wibuy:sync-payments')->everyFiveMinutes()->withoutOverlapping();       // deposits, refunds, payouts older than 2 min
Schedule::command('wibuy:expire-groups')->everyMinute()->withoutOverlapping();               // group buys that did not fill in time: refund + restock
Schedule::command('wibuy:cancel-unpaid')->everyFiveMinutes()->withoutOverlapping();       // checkouts not paid in 15 min
Schedule::command('wibuy:refund-unaccepted')->everyFiveMinutes()->withoutOverlapping();   // paid, not accepted in 24 h
Schedule::command('wibuy:complete-orders')->everyFiveMinutes()->withoutOverlapping();     // shipped/delivered 3 days, no dispute
Schedule::command('wibuy:prune-otp')->daily();
Schedule::command('wibuy:rewards-tick')->everyFiveMinutes()->withoutOverlapping();        // "Remind me" SMS for lightning deals, expiry
Schedule::command('wibuy:rotate-deals')->dailyAt('20:00');                                // tomorrow's automatic lightning deals
Schedule::command('wibuy:daily-rewards')->dailyAt('09:00');
Schedule::command('wibuy:reliability')->dailyAt('03:00');                                 // shop delivery reliability scores                               // WiBuy Pass reminders, trial review deadlines
