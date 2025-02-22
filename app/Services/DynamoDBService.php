<?php

namespace App\Services;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;

class DynamoDBService
{
    protected $client;
    protected $table;

    public function __construct()
    {
        $this->client = new DynamoDbClient([
            'region' => env('AWS_DEFAULT_REGION'),
            'version' => 'latest',
            'credentials' => [
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
            ]
        ]);

        $this->table = env('AWS_DYNAMODB_TABLE');
    }

    public function storeTransaction(array $transactionData)
    {
        try {

            $response = $this->client->putItem([
                'TableName' => $this->table,
                'Item' => [
                    'transaction_id' => ['S' => $transactionData['transaction_id']],
                    'blockchain' => ['S' => $transactionData['blockchain']],
                    'from_address' => ['S' => $transactionData['from_address']],
                    'to_address' => ['S' => $transactionData['to_address']],
                    'amount' => ['N' => (string)$transactionData['amount']],
                    'timestamp' => ['N' => (string)$transactionData['timestamp']],
                ]
            ]);

            return $response;

        } catch (DynamoDbException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function getTransactionById(string $transactionId)
    {
        try {
            $response = $this->client->getItem([
                'TableName' => $this->table,
                'Key' => [
                    'transaction_id' => ['S' => $transactionId]
                ]
            ]);

            return !empty($response['Item']) ? $this->formatItem($response['Item']) : null;
        } catch (DynamoDbException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function getTransactionsByFilter(array $filters)
    {
        try {
            $expression = [];
            $values = [];
            $names = [];

            foreach ($filters as $key => $value) {
                $placeholder = '#' . $key;
                $valuePlaceholder = ':' . $key;
                $expression[] = "$placeholder = $valuePlaceholder";
                $values[$valuePlaceholder] = ['S' => $value];
                $names[$placeholder] = $key;
            }

            $response = $this->client->scan([
                'TableName' => $this->table,
                'FilterExpression' => implode(' AND ', $expression),
                'ExpressionAttributeValues' => $values,
                'ExpressionAttributeNames' => $names,
            ]);

            return array_map([$this, 'formatItem'], $response['Items']);
        } catch (DynamoDbException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function formatItem($item)
    {
        return [
            'transaction_id' => $item['transaction_id']['S'],
            'blockchain' => $item['blockchain']['S'],
            'from_address' => $item['from_address']['S'],
            'to_address' => $item['to_address']['S'],
            'amount' => (float) $item['amount']['N'],
            'timestamp' => (int) $item['timestamp']['N'],
        ];
    }


}
