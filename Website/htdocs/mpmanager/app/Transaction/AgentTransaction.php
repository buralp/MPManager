<?php


namespace App\Transaction;


use App\Lib\ITransactionProvider;
use App\Models\Agent;
use App\Models\AgentBalanceHistory;
use App\Models\AgentCommission;
use App\Models\Transaction\Transaction;
use App\Models\Transaction\TransactionConflicts;
use App\Services\FirebaseService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AgentTransaction implements ITransactionProvider
{
    private $fireBaseService;
    /**
     * @var \App\Models\Transaction\AgentTransaction
     */
    private $agentTransaction;

    /**
     * @var Transaction
     */
    private $transaction;

    /**
     * contains validated data
     * @var array
     */
    private $validData;

    public function __construct(
        \App\Models\Transaction\AgentTransaction $agentTransaction,
        Transaction $transaction,
        FirebaseService $firebaseService
    ) {
        $this->agentTransaction = $agentTransaction;
        $this->transaction = $transaction;
        $this->fireBaseService = $firebaseService;
    }

    public function saveTransaction(): void
    {

        $this->agentTransaction = new \App\Models\Transaction\AgentTransaction();
        $this->transaction = new Transaction();
        //assign data
        $this->assignData($this->validData);

        //save transaction
        $this->saveData($this->agentTransaction);
    }

    private function assignData($data): void
    {

        //provider specific data
        $this->agentTransaction->agent_id = (int)$data['agent_id'];
        $this->agentTransaction->device_id = (int)$data['device_id'];


        // common transaction data
        $this->transaction->amount = (int)$data['amount'];
        $this->transaction->sender = 'Agent-' . $data['agent_id'];
        $this->transaction->message = $data['meter_serial_number'];
        $this->transaction->type = 'energy';
        $this->transaction->original_transaction_type = 'agent_transaction';
    }

    public function saveData(\App\Models\Transaction\AgentTransaction $agentTransaction): void
    {
        $agentTransaction->save();
    }

    public function sendResult(bool $requestType, Transaction $transaction): void
    {
        $this->agentTransaction->update(['status' => $requestType === true ? 1 : -1]);

        if (!$requestType) {
            return;
        }

        $body = $this->prepareRequest($transaction);

        $agent = $this->agentTransaction->agent;


        $history = AgentBalanceHistory::query()->make([
            'agent_id' => $agent->id,
            'amount' => -$transaction->amount,
            'transaction_id' => $transaction->id,

        ]);
        $history->trigger()->associate($this->agentTransaction);
        $history->save();


        //create agent commission
        $commission = AgentCommission::query()->find($agent->agent_commission_id);

        $history = AgentBalanceHistory::query()->make([
            'agent_id' => $agent->id,
            'amount' => ($transaction->amount * $commission->energy_commission),
            'transaction_id' => $transaction->id,
        ]);
        $history->trigger()->associate($commission);
        $history->save();

        if (app()->environment('production')) {
            $this->fireBaseService->sendNotify($agent->fire_base_token, json_encode((string)$body));
        }

    }

    private function prepareRequest(Transaction $transaction)
    {
        return Transaction::with('token', 'originalTransaction', 'originalTransaction.conflicts', 'sms', 'token.meter',
            'token.meter.meterParameter', 'token.meter.meterType', 'paymentHistories')->where('id',
            $transaction->id)->first();
    }

    /**
     * @param $request
     * @throws \Exception
     */
    public function validateRequest($request): void
    {

        $deviceId = request()->header('device-id');
        $agent = Agent::query()->find(auth('agent_api')->user()->id);
        $agentId = $agent->id;

        $agent = auth('agent_api')->user();
        try {
            Agent::query()
                ->where('device_id', $deviceId)
                ->where('id', $agentId)
                ->firstOrFail();

        } catch (ModelNotFoundException $e) {
            throw new \Exception($e->getMessage());
        }
        if ($agentId !== $agent->id) {
            throw new \Exception('Agent authorization failed.');
        }
        $this->validData = request()->only(['meter_serial_number', 'amount']);
        $this->validData['device_id'] = $deviceId;
        $this->validData['agent_id'] = $agentId;
    }

    public function confirm(): void
    {
        // No need to confirm the trigger request
    }

    public function getMessage(): string
    {
        return $this->transaction->message;
    }

    public function getAmount(): int
    {
        return $this->transaction->amount;
    }

    public function getSender(): string
    {
        return $this->transaction->sender;
    }

    public function saveCommonData(): Model
    {

        return $this->agentTransaction->transaction()->save($this->transaction);

    }

    public function init($transaction): void
    {
        $this->agentTransaction = $transaction;
        $this->transaction = $transaction->transaction()->first();
    }

    public function addConflict(?string $message): void
    {
        $conflict = new TransactionConflicts();
        $conflict->state = $message;
        $conflict->transaction()->associate($this->agentTransaction);
        $conflict->save();
    }

    public function getTransaction(): Transaction
    {
        return $this->transaction;
    }
}