<?php

namespace App\Http\Controllers\API;

use Midtrans\Config;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Helpers\ResponseFormatter;
use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Support\Facades\Auth;
use Midtrans\Snap;

class TransactionController extends Controller
{
    public function all(Request $request)
    {
        // Declare a variable
        $id = $request->input('id');
        $limit = $request->input('limit', 6);
        $food_id = $request->input('food_id');
        $status = $request->input('status');

        // Decision from transaction
        if($id) {
            $transaction = Transaction::with(['food', 'user'])->find($id);

            if($transaction) {
                return ResponseFormatter::success(
                    $transaction,
                    'Transaction data retrieved successfully'
                );
            } else {
                return ResponseFormatter::error(
                    null,
                    'Transaction data failed to retrieve',
                    404
                );
            }
        }

        // Adding relation from model
        $transaction = Transaction::with(['user', 'food'])
                                    ->where('user_id', Auth::user()->id);

        if($food_id) {
            $transaction->where('food_id', $food_id);
        }

        if($status) {
            $transaction->where('status', $status);
        }

        return ResponseFormatter::success(
            $transaction->paginate($limit),
            'Transaction list data was successfully retrieved'
        );
    }

    public function update(Request $request, $id)
    {
        // Declare a variable
        $transaction = Transaction::findOrFail($id);

        // Update transaction
        $transaction->update($request->all());
        return ResponseFormatter::success($transaction, 'Transaction has updated');
    }

    public function checkout(Request $request)
    {
        // Form Validation
        $request->validate([
            'food_id'   => 'required|exists:food_id',
            'user_id'   => 'required|exists:user_id',
            'quantity'  => 'required',
            'total'     => 'required',
            'status'    => 'required'
        ]);

        // Store data
        $transaction = Transaction::create([
            'food_id'       => $request->food_id,
            'user_id'       => $request->user_id,
            'quantity'      => $request->quantity,
            'total'         => $request->total,
            'status'        => $request->status,
            'payment_url'   => '',
        ]);

        // Midtrans config
        Config::$serverKey = config('services.midtrans.serverKey');
        Config::$isProduction = config('services.midtrans.isProd$isProduction');
        Config::$isSanitized = config('services.midtrans.isSan$isSanitized');
        Config::$is3ds = config('services.midtrans.is$is3ds');

        // Call recent transaction
        $transaction = Transaction::with(['food', 'user'])->find($transaction->id);

        // Create transaction with midtrans
        $midtrans = [
            'transaction_details' => [
                'order_id'      => $transaction->id,
                'gross_amount'  => (int) $transaction->total
            ],
            'customer_details' => [
                'name'  =>  $transaction->user->name,
                'email' =>  $transaction->user->email
            ],
            'enabled_payments' => ['gopay', 'bank_transfer'],
            'vtweb' => []
        ];

        // Call midtrans
        try {
            // Retrieve midtrans payment page
            $paymentUrl = Snap::createTransaction($midtrans)->redirect_url;

            $transaction->payment_url = $paymentUrl;
            $transaction->save();

            // Return data to API
            return ResponseFormatter::success($transaction, 'Transaction is sucessful');
        } catch (Exception $e) {
            return ResponseFormatter::error($e->getMessage(), 'Transaction is failed');
        }
    }
}