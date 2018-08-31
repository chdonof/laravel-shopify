<?php

namespace OhMyBrew\ShopifyApp\Traits;

use Carbon\Carbon;
use OhMyBrew\ShopifyApp\Facades\ShopifyApp;
use OhMyBrew\ShopifyApp\Libraries\BillingPlan;
use OhMyBrew\ShopifyApp\Models\Charge;
use OhMyBrew\ShopifyApp\Models\Shop;
use OhMyBrew\ShopifyApp\Models\Plan;

trait BillingControllerTrait
{
    /**
     * Redirects to billing screen for Shopify.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        // Get the confirmation URL
        $shop = ShopifyApp::shop();
        $billingPlan = new BillingPlan($shop, $this->getPlan());

        // Do a fullpage redirect
        return view('shopify-app::billing.fullpage_redirect', [
            'url' => $billingPlan->getConfirmationUrl(),
        ]);
    }

    /**
     * Processes the response from the customer.
     *
     * @return void
     */
    public function process()
    {
        // Setup the shop and get the charge ID passed in
        $shop = ShopifyApp::shop();
        $chargeId = request('charge_id');

        // Setup the plan and get the charge
        $plan = $this->getPlan();
        $billingPlan = new BillingPlan($shop, $plan);
        $billingPlan->setChargeId($chargeId);
        $status = $billingPlan->getCharge()->status;

        // Grab the plan detailed used
        $planDetails = $this->plan->getChargeParams();
        unset($planDetails['return_url']);

        // Create a charge (regardless of the status)
        $charge = new Charge();
        $charge->type = $this->chargeType() === 'recurring' ? Charge::CHARGE_RECURRING : Charge::CHARGE_ONETIME;
        $charge->charge_id = $chargeId;
        $charge->status = $status;
        $charge->plan_id = $plan->id;

        // Check the customer's answer to the billing
        if ($status === 'accepted') {
            // Activate and add details to our charge
            $response = $billingPlan->activate();
            $charge->status = $response->status;
            $charge->billing_on = $response->billing_on;
            $charge->trial_ends_on = $response->trial_ends_on;
            $charge->activated_on = $response->activated_on;

            // Set old charge as cancelled, if one
            $lastCharge = $this->getLastCharge($shop);
            if ($lastCharge) {
                $lastCharge->status = 'cancelled';
                $lastCharge->save();
            }
        } else {
            // Customer declined the charge
            $charge->status = 'declined';
            $charge->cancelled_on = Carbon::today()->format('Y-m-d');
        }

        // Merge in the plan details since the fields match the database columns
        foreach ($planDetails as $key => $value) {
            $charge->{$key} = $value;
        }

        // Save and link to the shop
        $shop->charges()->save($charge);

        if ($status === 'declined') {
            // Show the error... don't allow access
            return abort(403, 'It seems you have declined the billing charge for this application');
        }

        // All good, update the shop's plan and take them off freeium (if applicable)
        $shop->freemium = false;
        $shop->plan_id = $plan->id;
        $shop->save();

        // Go to homepage of app
        return redirect()->route('home');
    }

    /**
     * Get the plan to use.
     *
     * @return Plan
     */
    protected function getPlan()
    {
        return Plan::where(function ($q) {
            $q->where('plan_id', request('plan_id'))->orWhere('on_install', true);
        })->first();
    }

    /**
     * Gets the last single or recurring charge for the shop.
     *
     * @param object $shop The shop object.
     *
     * @return null|Charge
     */
    protected function getLastCharge(Shop $shop)
    {
        return $shop->charges()
            ->whereIn('type', [Charge::CHARGE_RECURRING, Charge::CHARGE_ONETIME])
            ->orderBy('created_at', 'desc')
            ->first();
    }
}
