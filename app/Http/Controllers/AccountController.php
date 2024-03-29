<?php

namespace App\Http\Controllers;

use App\Http\Requests\Account\ApproveRequest;
use App\Http\Requests\Account\ConfirmRequest;
use App\Http\Requests\Account\CreateRequest;
use App\Http\Requests\Account\UpdateRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Models\AccountType;
use App\Models\Validatorable;
use DB;
use Illuminate\Http\Request;
use Laravel\Scout\Builder;
use Storage;

class AccountController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if ($search = request('_search')) {
            $accounts = Account::search($search);
        } else {
            $accounts = Account::orderBy('id', 'desc');
        }

        if ($creatorId = request('_creatorId')) {
            $accounts = $accounts->where('creator_id', $creatorId);
        }

        if (request('_confirmedErrorAndApprovableByMe') && auth()->check()) {
            $accountTypeIdsCreatedByMe = AccountType::where('creator_id', auth()->user()->getKey())
                ->get(['id'])
                ->pluck('id')
                ->toArray();

            if ($accounts instanceof Builder) {
                $accounts = $accounts->whereIn('account_type_id', $accountTypeIdsCreatedByMe)
                    ->with([
                        'filters' => '_tags:confirmed_at_is_null AND NOT _tags:bought_at_is_null AND _tags:refunded_at_is_null',
                    ]);
            } else {
                $accounts = $accounts->whereIn('account_type_id', $accountTypeIdsCreatedByMe)
                    ->where('confirmed_at', null)
                    ->where('bought_at', '!=', null)
                    ->where('refunded_at', null);
            }
        }

        if (request('_perPage')) {
            $accounts = $accounts->paginate(request('_perPage'));
        } else {
            $accounts = $accounts->get();
        }

        return AccountResource::withLoad($accounts);
    }

    /**
     * Get accounts that created by me
     *
     */
    public function getCreatedByMe()
    {
        if (request('_search')) {
            $accounts = Account::search(request('_search'))
                ->where('creator_id', auth()->user()->getKey());
        } else {
            $accounts = Account::where('creator_id', auth()->user()->getKey())
                ->orderBy('id', 'desc');
        }

        if (request('_perPage')) {
            $accounts = $accounts->paginate(request('_perPage'));
        } else {
            $accounts = $accounts->get();
        }

        return AccountResource::withLoad($accounts);
    }

    /**
     * Get accounts bought by me
     *
     * @return \Illuminate\Http\Response
     */
    public function getBoughtByMe()
    {
        if (request('_search')) {
            $accounts = Account::search(request('_search'))
                ->where('buyer_id', auth()->user()->getKey());
        } else {
            $accounts = Account::where('buyer_id', auth()->user()->getKey())
                ->orderBy('id', 'desc');
        }

        if (request('_perPage')) {
            $accounts = $accounts->paginate(request('_perPage'));
        } else {
            $accounts = $accounts->get();
        }

        return AccountResource::withLoad($accounts);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(CreateRequest $request, AccountType $accountType, Account $account)
    {
        try {
            DB::beginTransaction();

            $data = $request->only('description', 'cost', 'price');
            $data['account_type_id'] = $accountType->getKey();
            $data['tax'] = 0;

            $account = $account->create($data);
            $account->tag($request->tags);

            $syncCreatorInfos = [];
            foreach ($request->creatorInfos as $creatorInfo) {
                $syncCreatorInfos[$creatorInfo['id']] = ['value' => $creatorInfo['pivot']['value']];
            }
            $account->creatorInfos()->attach($syncCreatorInfos);

            foreach ($request->images as $order => $image) {
                $account->images()->create([
                    'order' => $order,
                    'path' => $image->store('account-images', 'public'),
                ]);
            }

            $account->validate(Validatorable::CREATED_TYPE);

            DB::commit();
        } catch (\Throwable $th) {
            Storage::delete($account->images->pluck('path'));
            DB::rollBack();
            throw $th;
        }

        return AccountResource::withLoad($account);
    }

    /**
     * Display the specified resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function show(Account $account)
    {
        return AccountResource::withLoad($account);
    }

    /**
     * Handle buy an account for user.
     *
     * @return \Illuminate\Http\Response
     */
    public function buy(Account $account)
    {
        try {
            DB::beginTransaction();

            $bestPrice = $account->price;
            auth()->user()->updateBalance(-$bestPrice, 'mua tài khoản #' . $account->getKey());
            $account->update([
                'bought_at' => now(),
                'buyer_id' => auth()->user()->getKey(),
                'confirmed_at' => now()->addHour(), // Auto confirmed after 1 hour
                'bought_at_price' => $bestPrice,
            ]);
            $account->log('mua tài khoản');

            $account->validate(Validatorable::BOUGHT_TYPE);

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }

        return AccountResource::withLoad($account);
    }

    /**
     * buyer confirm that bought account is oke or not.
     *
     * @return \Illuminate\Http\Response
     */
    public function confirm(ConfirmRequest $request, Account $account)
    {
        try {
            DB::beginTransaction();

            if ($account->confirmed_at && now()->lte($account->confirmed_at)) {
                $account->update([
                    'confirmed_at' => $request->oke ? now() : null,
                ]);

                if ($request->oke) $account->log('xác nhận là tài khoản đúng thông tin');
            }

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }

        return AccountResource::withLoad($account);
    }

    /**
     * Approve confirmed error account
     *
     */
    public function approve(ApproveRequest $request, Account $account)
    {
        try {
            DB::beginTransaction();

            if ($request->isRefunded) {
                $account->buyer->updateBalance($account->bought_at_price, 'hoàn trả tiền tài khoản #' . $account->getKey());
                $account->update([
                    'refunded_at' => now(),
                ]);
            } else {
                $account->update([
                    'confirmed_at' => now(),
                ]);
            }

            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }

        return AccountResource::withLoad($account);
    }

    /**
     * Update the specified resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function update(UpdateRequest $request, Account $account)
    {
        try {
            DB::beginTransaction();
            $newImagePaths = [];

            $data = $request->only('description', 'cost', 'price');

            $account->update($data);
            if ($request->tags) $account->tag($request->tags);

            if (is_array($request->creatorInfos)) {
                $syncCreatorInfos = [];
                foreach ($request->creatorInfos as $creatorInfo) {
                    $syncCreatorInfos[$creatorInfo['id']] = ['value' => $creatorInfo['pivot']['value']];
                }
                $account->creatorInfos()->sync($syncCreatorInfos);
            }

            if ($request->images) {
                $oldImages = $account->images;
                foreach ($request->images as $order => $image) {
                    $account->images()->create([
                        'order' => $order,
                        'path' => $newImagePaths[] = $image->store('account-images', 'public'),
                    ]);
                }
                $oldImages->each(fn ($oldImage) => $oldImage->delete());
            }

            $account->validate(Validatorable::UPDATED_TYPE);

            DB::commit();
        } catch (\Throwable $th) {
            Storage::delete($newImagePaths);
            DB::rollBack();
            throw $th;
        }

        return AccountResource::withLoad($account);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function delete(Account $account)
    {
        //
    }
}
