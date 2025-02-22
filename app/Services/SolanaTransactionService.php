<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Exceptions\SolanaTransactionRetrievalException;

class SolanaTransactionService
{
    /**
     * The Guzzle HTTP client.
     *
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * The Solana RPC API endpoint.
     *
     * @var string
     */
    protected $rpcEndpoint;

    /**
     * Constructor.
     *
     * @param \GuzzleHttp\Client|null $client
     */
    public function __construct(Client$client = null)
    {
        $this->client = $client ?: new Client();
        $this->rpcEndpoint = config('services.solana.rpc_endpoint'); // Load from config
    }

    public function getTokenHoldingsOfWallet(string $walletId, ?string $token = null)
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'getProgramAccounts',
            'params' => [
                'TokenkegQfeZyiNwAJbNbGKPFXCWuBvf9Ss623VQ5DA',
                [
                    'encoding' => 'jsonParsed',
                    'filters' => [
                        [
                            'dataSize' => 165
                        ],
                        [
                            'memcmp' => [
                                'offset' => 32,
                                'bytes' => $walletId
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $response = Http::withHeader('Content-Type', 'application/json')->post($this->rpcEndpoint, $payload)->json();

        if (!isset($response['result'])) {
            logger()->debug("Invalid format retrieved from blockchain", [
                'payload' => $payload,
                'response' => $response
            ]);

            return null;
        }

        if (!$token) {
            return $response['result'];
        }

        foreach ($response['result'] as $item) {

            $info = $item['account']['data']['parsed']['info'];

            if ($info['mint'] === $token) {
                return $item;
            }

        }

        return null;

    }

    /**
     * Retrieves the largest accounts for a specific SPL token mint.
     *
     * @param string $mintAddress The public key of the token mint to query, as a base-58 encoded string.
     * @param string|null $commitment Optional commitment level ('processed', 'confirmed', or 'finalized').
     *
     * @return array An array of token account objects, or an empty array if an error occurs.
     *
     * @throws \App\Exceptions\SolanaTransactionRetrievalException If an error occurs while retrieving the accounts.
     */
    public function getTokenLargestAccounts(string $mintAddress, string $commitment = null): array
    {
        try {
            $params = [$mintAddress];

            if ($commitment !== null) {
                $params[] = [
                    'commitment' => $commitment,
                ];
            }

            $requestPayload = [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'getTokenLargestAccounts',
                'params' => $params,
            ];

            $response = $this->client->post($this->rpcEndpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($requestPayload),
            ]);

            // Check the response status code.
            if ($response->getStatusCode() !== 200) {
                Log::error('Solana RPC request failed with status code: ' . $response->getStatusCode());
                throw new SolanaTransactionRetrievalException('Solana RPC request failed.');
            }

            // Decode the response body.
            $responseBody = json_decode($response->getBody(), true);

            // Check for errors in the response.
            if (isset($responseBody['error'])) {
                Log::error('Solana RPC error: ' . json_encode($responseBody['error']));
                throw new SolanaTransactionRetrievalException('Solana RPC error: ' . $responseBody['error']['message']);
            }

            // Extract the account information from the response
            return $responseBody['result']['value'] ?? [];

        } catch (\Exception $e) {
            Log::error('Error retrieving largest token accounts: ' . $e->getMessage());
            throw new SolanaTransactionRetrievalException('Failed to retrieve largest token accounts: ' . $e->getMessage());
        }
    }

    /**
     * Retrieves transactions from the Solana blockchain for a given SLP hash.
     *
     * @param string $slpHash The SLP hash to search for.
     * @param int $limit The maximum number of transactions to retrieve (optional, default 100).
     * @param string|null $before The transaction signature to start from (optional, for pagination).
     * @param string|null $until The transaction signature to end at (optional, for pagination).
     *
     * @return array An array of transaction objects, or an empty array if no transactions are found.
     *
     * @throws \App\Exceptions\SolanaTransactionRetrievalException If an error occurs while retrieving the transactions.
     */
    public function getTransactionsBySlpHash(string $slpHash, int $limit = 100, string $before = null, string $until = null): array
    {
        try {
            // Construct the request payload for getProgramAccounts.  We are looking for transactions
            // that involve the SLP token program and a specific token account.
            $requestPayload = [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'getProgramAccounts',
                'params' => [
                    config('services.solana.slp_program_id'), // The SLP Token Program ID (e.g., TokenkegQfeZyiNwmdzWKkW9nkYX2uGSj73t7serns)
                    [
                        'encoding' => 'jsonParsed',
                        'filters' => [
                            [
                                'memcmp' => [  // Filter by the token account's mint.
                                    'offset' => 0, // Account data offset
                                    'bytes' => $slpHash, // The mint of the SLP token (your SLP Hash)
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            // Make the API request.
            $response = $this->client->post($this->rpcEndpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($requestPayload),
            ]);

            // Check the response status code.
            if ($response->getStatusCode() !== 200) {
                Log::error('Solana RPC request failed with status code: ' . $response->getStatusCode());
                throw new SolanaTransactionRetrievalException('Solana RPC request failed.');
            }

            // Decode the response body.
            $responseBody = json_decode($response->getBody(), true);

            // Check for errors in the response.
            if (isset($responseBody['error'])) {
                Log::error('Solana RPC error: ' . json_encode($responseBody['error']));
                throw new SolanaTransactionRetrievalException('Solana RPC error: ' . $responseBody['error']['message']);
            }

            // Extract the account information from the response
            $accounts = $responseBody['result'];

            $transactions = [];

            // Iterate through the found accounts and fetch the transactions related to them.
            foreach ($accounts as $account) {
                $accountPublicKey = $account['pubkey'];

                // Fetch transactions using getConfirmedSignaturesForAddress2
                $transactionSignatures = $this->getSignaturesForAddress($accountPublicKey, $limit, $before, $until);

                foreach ($transactionSignatures as $signature) {
                    // Fetch the full transaction details
                    $transactionDetails = $this->getTransaction($signature['signature']);

                    if ($transactionDetails) {
                        $transactions[] = $transactionDetails;
                    }
                }
            }


            return $transactions;

        } catch (\Exception $e) {
            Log::error('Error retrieving Solana transactions: ' . $e->getMessage());
            throw new SolanaTransactionRetrievalException('Failed to retrieve Solana transactions: ' . $e->getMessage());
        }
    }

    /**
     * Gets transaction signatures for a given address.
     *
     * @param string $address The Solana address.
     * @param int $limit The maximum number of signatures to retrieve.
     * @param string|null $before The transaction signature to start from (optional, for pagination).
     * @param string|null $until The transaction signature to end at (optional, for pagination).
     *
     * @return array An array of transaction signatures.
     *
     * @throws \App\Exceptions\SolanaTransactionRetrievalException
     */
    protected function getSignaturesForAddress(string $address, int $limit = 100, string $before = null, string $until = null): array
    {
        try {
            $params = [
                $address,
                [
                    'limit' => $limit,
                    'before' => $before,
                    'until' => $until,
                ],
            ];

            $requestPayload = [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'getConfirmedSignaturesForAddress2',
                'params' => $params,
            ];

            $response = $this->client->post($this->rpcEndpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($requestPayload),
            ]);

            if ($response->getStatusCode() !== 200) {
                Log::error('Solana RPC request failed with status code: ' . $response->getStatusCode());
                throw new SolanaTransactionRetrievalException('Solana RPC request failed.');
            }

            $responseBody = json_decode($response->getBody(), true);

            if (isset($responseBody['error'])) {
                Log::error('Solana RPC error: ' . json_encode($responseBody['error']));
                throw new SolanaTransactionRetrievalException('Solana RPC error: ' . $responseBody['error']['message']);
            }

            return $responseBody['result'] ?? [];

        } catch (\Exception $e) {
            Log::error('Error retrieving transaction signatures: ' . $e->getMessage());
            throw new SolanaTransactionRetrievalException('Failed to retrieve transaction signatures: ' . $e->getMessage());
        }
    }

    /**
     * Gets the details of a specific transaction.
     *
     * @param string $signature The transaction signature.
     *
     * @return array|null The transaction details, or null if the transaction is not found.
     *
     * @throws \App\Exceptions\SolanaTransactionRetrievalException
     */
    protected function getTransaction(string $signature): ?array
    {
        try {
            $requestPayload = [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'getTransaction',
                'params' => [
                    $signature,
                    [
                        'encoding' => 'jsonParsed',
                        'commitment' => 'confirmed',
                    ],
                ],
            ];

            $response = $this->client->post($this->rpcEndpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($requestPayload),
            ]);

            if ($response->getStatusCode() !== 200) {
                Log::error('Solana RPC request failed with status code: ' . $response->getStatusCode());
                throw new SolanaTransactionRetrievalException('Solana RPC request failed.');
            }

            $responseBody = json_decode($response->getBody(), true);

            if (isset($responseBody['error'])) {
                Log::warning('Solana RPC error retrieving transaction ' . $signature . ': ' . json_encode($responseBody['error']));
                return null; // Return null if the transaction is not found or an error occurs
            }

            return $responseBody['result'] ?? null;

        } catch (\Exception $e) {
            Log::error('Error retrieving transaction ' . $signature . ': ' . $e->getMessage());
            throw new SolanaTransactionRetrievalException('Failed to retrieve transaction ' . $signature . ': ' . $e->getMessage());
        }
    }

    /**
     * Retrieves the largest accounts from the Solana blockchain.
     *
     * @param string|null $filter Optional filter for account type ('circulating' or 'nonCirculating').
     *
     * @return array An array of account objects, or an empty array if an error occurs.
     *
     * @throws \App\Exceptions\SolanaTransactionRetrievalException If an error occurs while retrieving the accounts.
     */
    public function getLargestAccounts(string $filter = null): array
    {
        try {
            $params = [];

            if ($filter !== null) {
                $params = [
                    'commitment' => 'confirmed', // Optional commitment level
                    'filter' => $filter,
                ];
            }

            $requestPayload = [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'getLargestAccounts',
                'params' => $params ? [$params] : [], // If no params, send an empty array
            ];

            $response = $this->client->post($this->rpcEndpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($requestPayload),
            ]);

            // Check the response status code.
            if ($response->getStatusCode() !== 200) {
                Log::error('Solana RPC request failed with status code: ' . $response->getStatusCode());
                throw new SolanaTransactionRetrievalException('Solana RPC request failed.');
            }

            // Decode the response body.
            $responseBody = json_decode($response->getBody(), true);

            // Check for errors in the response.
            if (isset($responseBody['error'])) {
                Log::error('Solana RPC error: ' . json_encode($responseBody['error']));
                throw new SolanaTransactionRetrievalException('Solana RPC error: ' . $responseBody['error']['message']);
            }

            // Extract the account information from the response
            return $responseBody['result']['value'] ?? [];

        } catch (\Exception $e) {
            Log::error('Error retrieving largest Solana accounts: ' . $e->getMessage());
            throw new SolanaTransactionRetrievalException('Failed to retrieve largest Solana accounts: ' . $e->getMessage());
        }
    }

}
