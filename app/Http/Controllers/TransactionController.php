<?php

namespace App\Http\Controllers;

use App\Domain\Currency\Contracts\CurrencyRepository;
use App\Domain\Wallet\Dto\AddMoneyTransactionData;
use App\Domain\Wallet\Dto\AddMoneyTransactionItemData;
use App\Domain\Wallet\Dto\AddMoneyTransactionItemRequestData;
use App\Domain\Wallet\Dto\AddMoneyTransactionRequestData;
use App\Domain\Wallet\Dto\WithdrawMoneyTransactionData;
use App\Domain\Wallet\Dto\WithdrawMoneyTransactionItemData;
use App\Domain\Wallet\Dto\WithdrawMoneyTransactionItemRequestData;
use App\Domain\Wallet\Dto\WithdrawMoneyTransactionRequestData;
use App\Domain\Wallet\Projections\Denomination;
use App\Domain\Wallet\Projections\Wallet;
use App\Domain\Wallet\Queries\TransactionIndexQuery;
use App\Domain\Wallet\Resource\WithdrawMoneyTransactionResponseResource;
use App\Domain\Wallet\Resources\AddMoneyTransactionResponseResource;
use App\Domain\Wallet\Resources\TransactionResource;
use App\Domain\Wallet\Resources\WalletResource;
use App\Domain\Wallet\WalletAggregateRoot;
use Cache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\AbstractPaginator;
use Spatie\LaravelData\DataCollection;
use Str;

class TransactionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, TransactionIndexQuery $query): AbstractPaginator|DataCollection
    {
        $cacheKey = $request->cacheKey();

        return Cache::tags(['transactions', auth()->id()])
            ->remember(
                $cacheKey,
                now()->addMinutes(5),
                fn () => TransactionResource::collect($query->paginate())
            );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function deposit(Wallet $wallet, AddMoneyTransactionRequestData $data, CurrencyRepository $currencyRepository)
    {
        $denominationIds = collect($data->denominations)
            ->map(fn (AddMoneyTransactionItemRequestData $denomination) => $denomination->denominationId)
            ->values();

        $denominationIdQuantityMapping = collect($data->denominations)
            ->mapWithKeys(fn (AddMoneyTransactionItemRequestData $denomination) => [$denomination->denominationId => $denomination->quantity])
            ->all();

        /** @var Collection<int, Denomination> */
        $denominations = Denomination::whereIn('uuid', $denominationIds)
            ->where('wallet_id', $wallet->getKey())
            ->get();

        $transactionGroupId = (string) Str::uuid();
        $dtos = $denominations
            ->map(function (Denomination $denomination) use ($denominationIdQuantityMapping, $transactionGroupId) {
                $transactionId = (string) Str::uuid();

                return new AddMoneyTransactionItemData(
                    $transactionId,
                    $transactionGroupId,
                    $denomination->getKey(),
                    $denomination->name,
                    $denomination->type,
                    $denomination->value,
                    $denominationIdQuantityMapping[$denomination->getKey()]
                );
            })
            ->values();

        WalletAggregateRoot::retrieve($wallet->getKey())
            ->addMoney(new AddMoneyTransactionData(
                $wallet->getKey(),
                $wallet->currency,
                $dtos->all()
            ))
            ->persist();

        // INVALIDATE ALL TRANSACTION CACHE FOR THIS USER
        Cache::tags(['transactions', auth()->id()])->flush();
        // INVALIDATE ALL WALLET CACHE FOR THIS USER
        Cache::tags(['wallets', auth()->id()])->flush();

        $wallet->refresh();

        return new AddMoneyTransactionResponseResource(
            true,
            'Money added successfully.',
            WalletResource::from($wallet)
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function withdraw(Wallet $wallet, WithdrawMoneyTransactionRequestData $data)
    {
        $denominationIds = collect($data->denominations)
            ->map(fn (WithdrawMoneyTransactionItemRequestData $denomination) => $denomination->denominationId)
            ->values();

        $denominationIdQuantityMapping = collect($data->denominations)
            ->mapWithKeys(fn (WithdrawMoneyTransactionItemRequestData $denomination) => [$denomination->denominationId => $denomination->quantity])
            ->all();

        /** @var Collection<int, Denomination> */
        $denominations = Denomination::whereIn('uuid', $denominationIds)
            ->where('wallet_id', $wallet->getKey())
            ->get();

        $transactionGroupId = (string) Str::uuid();
        $dtos = $denominations
            ->map(function (Denomination $denomination) use ($denominationIdQuantityMapping, $transactionGroupId) {
                $transactionId = (string) Str::uuid();

                return new WithdrawMoneyTransactionItemData(
                    $transactionId,
                    $transactionGroupId,
                    $denomination->getKey(),
                    $denomination->name,
                    $denomination->type,
                    $denomination->value,
                    $denominationIdQuantityMapping[$denomination->getKey()]
                );
            })
            ->values();

        WalletAggregateRoot::retrieve($wallet->getKey())
            ->withDrawMoney(new WithdrawMoneyTransactionData(
                $wallet->getKey(),
                $wallet->currency,
                $dtos->all()
            ))
            ->persist();

        // INVALIDATE ALL TRANSACTION CACHE FOR THIS USER
        Cache::tags(['transactions', auth()->id()])->flush();
        // INVALIDATE ALL WALLET CACHE FOR THIS USER
        Cache::tags(['wallets', auth()->id()])->flush();

        $wallet->refresh();

        return new WithdrawMoneyTransactionResponseResource(
            true,
            'Withdraw successful.',
            WalletResource::from($wallet)
        );
    }
}