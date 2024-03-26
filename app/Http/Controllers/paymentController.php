<?php

namespace App\Http\Controllers;

use App\Models\order;
use Illuminate\Http\Request;
use Razorpay\Api\Api;
use Illuminate\Support\Facades\Config;
use Session;
use Exception;
use Illuminate\Support\Str;
use Validator;
use App\Models\payment;
use MongoDB\BSON\UTCDateTime;
use App\Models\subscription;

class paymentController extends Controller
{
    public function makepayment(Request $request)
    {
        return view('razorpayView');
    }


    public function createorder(Request $request)
    {

        $RAZORPAY_KEY_ID = Config::get('services.razorpay.key_id');
        $RAZORPAY_KEY_SECRET = Config::get('services.razorpay.key_secret');

        $api = new Api($RAZORPAY_KEY_ID, $RAZORPAY_KEY_SECRET);

        try {
            $payment = $api->payment->fetch($request->razorpay_payment_id);

            $response = $api->payment->fetch($request->razorpay_payment_id)->capture(array('amount' => $payment['amount']));

            if ($response['status'] === 'captured') {
                Session::put('success', 'Payment successful');
            } else {
                Session::put('error', 'Payment capture failed');
            }
        } catch (Exception $e) {
            Session::put('error', $e->getMessage());
        }

        return redirect()->back();
    }



    //for api 
    public function createorders(Request $request)
    {
        $input = $request->all();
        $validation = Validator::make($input, [
            'amount' => 'required',
            'userId' => 'required',
            'type' => 'required',
            'duration' => $input['type'] === 'subscription' ? 'required' : '',
            'channelId' => $input['type'] === 'subscription' ? 'required' : ''
        ]);

        if ($validation->fails()) {
            $errors = $validation->errors()->first();
            return response()->json(['error_code' => $errors, 'status_code' => 400]);
        } else {
            try {
                $RAZORPAY_KEY_ID = config('services.razorpay.key_id');
                $RAZORPAY_KEY_SECRET = config('services.razorpay.key_secret');

                $amount = $request->amount * 100;
                $referenceId = Str::uuid()->toString();

                $orderData = [
                    'receipt' => $referenceId,
                    'amount' => $amount,
                    'currency' => 'INR',
                ];

                $api = new Api($RAZORPAY_KEY_ID, $RAZORPAY_KEY_SECRET);

                $order = $api->order->create($orderData);
                $order = [
                    'id' => $order->id,
                    'entity' => $order->entity,
                    'currency' => $order->currency,
                    'status' => $order->status,
                    'created_at' => $order->created_at
                ];

                $order = new order;
                $order->rzporderId = $order->id;
                $order->userId = $request->userId;
                $order->type = $request->type;
                $order->amount = $request->amount;
               //for subscription channel
                if ($request->type === 'subscription') {
                    $order->duration = $request->duration;
                    $order->channelId = $request->channelId;
                }
                $order->status ="Inprogress";
                $order->save();

                return response()->json(['status_code' => 200, 'data' => $order]);
            } catch (\Razorpay\Api\Errors\Error $e) {
                return response()->json(['status_code' => 400, 'error_code' => $e->getMessage()]);
            } catch (\Exception $e) {
                return response()->json(['status_code' => 400, 'error_code' => $e->getMessage()]);
            }
        }
    }


    public function verifypayment(Request $request)
    {
        $input = $request->all();
        $validation = Validator::make($input, [
            'razorpayPaymentId' => 'required',
        ]);

        if ($validation->fails()) {
            $errors = $validation->errors()->first();
            return response()->json(['error_code' => $errors, 'status_code' => 400]);
        } else {
            try {
                $RAZORPAY_KEY_ID = config('services.razorpay.key_id');
                $RAZORPAY_KEY_SECRET = config('services.razorpay.key_secret');

                $api = new Api($RAZORPAY_KEY_ID, $RAZORPAY_KEY_SECRET);

                $payment = $api->payment->fetch($request->razorpayPaymentId);

                if ($payment->status === 'captured') {
                    $payment = new payment;
                    $payment->razorpaymentId = $request->razorpaypaymentId;
                    $payment->razorpayorderId = $request->razorpayorderId;
                    $payment->razorpaysignature = $request->razorpaysignature;
                    $payment->paid_at = new UTCDateTime(now()->timestamp * 1000);
                    $payment->status = "completed";
                    $payment->save();

                    $order = order::where('rzporderId', $request->razorpayorderId)->first();
                    $order->status = "completed";
                    $order->save();

                    $paymentDetails = [
                        'paymentId' => $payment->id,
                        'paymentstatus' => $payment->status,
                        'paid_at' => $payment->paid_at
                    ];

                    if ($order->type === 'subscription') {
                        $this->processSubscriptionOrder($order,$paymentDetails);
                    }else{
                       
                    }

                    return response()->json(['status_code' => 200, 'message' => 'Payment has already been successfully captured.']);
                } else {
                    return response()->json(['status_code' => 400, 'message' => 'Payment has not been successfully captured yet.']);
                }
            } catch (\Exception $e) {
                return response()->json(['status_code' => 400, 'error_code' => $e->getMessage()]);
            }
        }
    }


    public function processSubscriptionOrder($order,$paymentDetails)
    {
        $subscriptionstartdate = new UTCDateTime($paymentDetails['paid_at']->getTimestamp() * 1000);
        switch ($order->duration) {
            case 'monthly':
                $endDate = $subscriptionstartdate->toDateTime()->add(new \DateInterval('P1M'));
                break;
            case 'quarterly':
                $endDate = $subscriptionstartdate->toDateTime()->add(new \DateInterval('P3M'));
                break;
            case 'yearly':
                $endDate = $subscriptionstartdate->toDateTime()->add(new \DateInterval('P1Y'));
                break;
        }
        $subscriptionenddate = new UTCDateTime($endDate->getTimestamp() * 1000);

        $subscription = new subscription;
        $subscription->rzporderId = $order->rzporderId;
        $subscription->userId = $order->userId;
        $subscription->amount =  $order->amount;
        $subscription->duration =  $order->duration;
        $subscription->channelId =  $order->channelId;
        $subscription->paymentId =  $paymentDetails['paymentId'];
        $subscription->paymentstatus =  $paymentDetails['paymentstatus'];
        $subscription->subscriptionstartdate = $subscriptionstartdate;
        $subscription->subscriptionenddate = $subscriptionenddate;
        $subscription->save();
    }
}
