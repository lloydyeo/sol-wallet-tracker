<?php

namespace App\Http\Controllers;

use App\Services\SolanaTransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class SolanaController extends Controller
{
    protected $solanaTransactionService;

    public function __construct(SolanaTransactionService $solanaTransactionService)
    {
        $this->solanaTransactionService = $solanaTransactionService;
    }

    public function snapshotTokenHolding(Request $request)
    {
        Artisan::call('solana:snapshot-latest-solana-token-holdings');

        $output = Artisan::output();

        return response()->json([
            'message' => 'Command executed successfully',
            'output' => $output
        ]);
    }

    public function getSlpTransactions(Request $request, $slpHash)
    {
        try {
            $transactions = $this->solanaTransactionService->getTransactionsBySlpHash($slpHash);

            // Process the transactions and store them in DynamoDB.
            // ... (Implementation to store in DynamoDB)

            return response()->json(['transactions' => $transactions]);

        } catch (\App\Exceptions\SolanaTransactionRetrievalException $e) {
            // Handle the exception (e.g., log the error, return an error response)
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
