<?php
/**
 * This file is part of the max-dernovyi/netsuite-php library.
 *
 * @copyright  Copyright (c) Max Dernovyi
 * @license    http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

namespace NetSuite\Rest\Handler;

use NetSuite\Classes\AddListResponse;
use NetSuite\Classes\DeleteListResponse;
use NetSuite\Classes\UpdateListResponse;
use NetSuite\Classes\UpsertListResponse;
use NetSuite\Rest\Http\RestClient;
use NetSuite\Rest\Record\RecordClient;
use Psr\Log\LoggerInterface;

final class HandlerFactory
{
    /**
     * @param callable(): RestClient $restClient builds the shared client on first use
     * @return array<string, callable(): OperationHandlerInterface> operation => lazy handler
     */
    public static function create(callable $restClient, ?LoggerInterface $logger = null): array
    {
        $records = self::once(function () use ($restClient) {
            return new RecordClient(call_user_func($restClient));
        });
        $get = self::once(function () use ($records) {
            return new GetHandler($records());
        });
        $add = self::once(function () use ($records) {
            return new AddHandler($records());
        });
        $update = self::once(function () use ($records) {
            return new UpdateHandler($records());
        });
        $upsert = self::once(function () use ($records) {
            return new UpsertHandler($records());
        });
        $delete = self::once(function () use ($records, $logger) {
            return new DeleteHandler($records(), $logger);
        });
        $list = function (callable $writer, string $responseClass, string $itemProperty, ?callable $prepare = null) {
            return function () use ($writer, $responseClass, $itemProperty, $prepare) {
                return new WriteListHandler($writer(), $responseClass, $itemProperty, $prepare);
            };
        };

        return [
            'get'        => $get,
            'getList'    => function () use ($get) {
                return new GetListHandler($get());
            },
            'add'        => $add,
            'addList'    => $list($add, AddListResponse::class, 'record'),
            'update'     => $update,
            'updateList' => $list($update, UpdateListResponse::class, 'record'),
            'upsert'     => $upsert,
            'upsertList' => $list($upsert, UpsertListResponse::class, 'record'),
            'delete'     => $delete,
            'deleteList' => $list($delete, DeleteListResponse::class, 'baseRef', function ($request) use ($delete) {
                $delete()->ignoreDeletionReason($request, 'deleteList');
            }),
        ];
    }

    /**
     * @param callable(): object $build
     * @return callable(): object the same instance on every call
     */
    private static function once(callable $build): callable
    {
        $instance = null;
        return function () use ($build, &$instance) {
            if ($instance === null) {
                $instance = $build();
            }
            return $instance;
        };
    }
}
