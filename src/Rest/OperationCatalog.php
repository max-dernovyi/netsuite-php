<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest;

/**
 * REST status of every NetSuiteService operation, from docs/design/compatibility-matrix.md.
 */
final class OperationCatalog
{
    const STATUSES = [
        'changePassword'               => 'no REST equivalent',
        'changeEmail'                  => 'no REST equivalent',
        'add'                          => 'supported, Record API',
        'delete'                       => 'supported, Record API',
        'search'                       => 'planned, SuiteQL',
        'searchMoreWithId'             => 'planned, SuiteQL paging',
        'update'                       => 'supported, Record API',
        'upsert'                       => 'supported, Record API',
        'addList'                      => 'supported, Record API',
        'deleteList'                   => 'supported, Record API',
        'updateList'                   => 'supported, Record API',
        'upsertList'                   => 'supported, Record API',
        'get'                          => 'supported, Record API',
        'getList'                      => 'supported, Record API',
        'getAll'                       => 'planned, record collection',
        'getSavedSearch'               => 'planned, saved search API',
        'getCustomizationId'           => 'planned, metadata catalog',
        'initialize'                   => 'planned, create-form',
        'initializeList'               => 'planned, create-form',
        'getSelectValue'               => 'planned, select options',
        'getItemAvailability'          => 'planned, SuiteQL',
        'getBudgetExchangeRate'        => 'planned, budgetExchangeRate record',
        'getCurrencyRate'              => 'planned, currencyRate record',
        'getDataCenterUrls'            => 'planned, derived from the account id',
        'getPostingTransactionSummary' => 'no documented REST equivalent',
        'getServerTime'                => 'planned, serverTime',
        'attach'                       => 'planned, !attach',
        'detach'                       => 'planned, !detach',
        'updateInviteeStatus'          => 'planned, calendarEvent update',
        'updateInviteeStatusList'      => 'planned, calendarEvent update',
        'asyncAddList'                 => 'planned, batch job',
        'asyncUpdateList'              => 'planned, batch job',
        'asyncUpsertList'              => 'planned, batch job',
        'asyncDeleteList'              => 'planned, batch job',
        'asyncGetList'                 => 'planned, batch job',
        'asyncInitializeList'          => 'no REST equivalent',
        'asyncSearch'                  => 'unverified on REST',
        'getAsyncResult'               => 'planned, async job API',
        'checkAsyncStatus'             => 'planned, async job API',
        'getDeleted'                   => 'planned, SuiteQL',
        'getAccountGovernanceInfo'     => 'planned, governanceLimits',
        'getIntegrationGovernanceInfo' => 'planned, governanceLimits',
    ];

    public static function status(string $operation): string
    {
        return self::STATUSES[$operation] ?? 'unknown operation';
    }
}
