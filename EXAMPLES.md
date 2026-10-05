# Usage examples

The examples assume a `$service` created as in the [README](README.md#quickstart).
All request, response and record classes live in `NetSuite\Classes`; the
[Schema Browser 2025.2](https://system.netsuite.com/help/helpcenter/en_US/srbrowser/Browser2025_2/schema/record/customer.html)
lists their fields.

* [Get a record by external id](#get-a-record-by-external-id)
* [Search with paging](#search-with-paging)
* [Add a customer](#add-a-customer)
* [Add a sales order with a custom field](#add-a-sales-order-with-a-custom-field)
* [Upsert by external id](#upsert-by-external-id)
* [Fulfill a sales order](#fulfill-a-sales-order)
* [Errors](#errors)
* [Resources](#resources)

## Get a record by external id

```php
use NetSuite\Classes\GetRequest;
use NetSuite\Classes\RecordRef;

$request = new GetRequest();
$request->baseRef = new RecordRef();
$request->baseRef->type = 'customer';
$request->baseRef->externalId = 'CUST-1001';

$read = $service->get($request)->readResponse;
if ($read->status->isSuccess) {
    $customer = $read->record;
}
```

## Search with paging

```php
use NetSuite\Classes\CustomerSearchBasic;
use NetSuite\Classes\SearchMoreWithIdRequest;
use NetSuite\Classes\SearchRequest;
use NetSuite\Classes\SearchStringField;

$service->setSearchPreferences(true, 100); // body fields only, 100 records per page

$search = new CustomerSearchBasic();
$search->email = new SearchStringField();
$search->email->operator = 'contains';
$search->email->searchValue = 'example.com';

$request = new SearchRequest();
$request->searchRecord = $search;
$result = $service->search($request)->searchResult;

while ($result->status->isSuccess) {
    foreach ($result->recordList->record ?? [] as $customer) {
        // ...
    }
    if ($result->pageIndex >= $result->totalPages) {
        break;
    }
    $more = new SearchMoreWithIdRequest();
    $more->searchId = $result->searchId;
    $more->pageIndex = $result->pageIndex + 1;
    $result = $service->searchMoreWithId($more)->searchResult;
}
```

## Add a customer

```php
use NetSuite\Classes\AddRequest;
use NetSuite\Classes\Customer;

$customer = new Customer();
$customer->externalId = 'CUST-1001';
$customer->companyName = 'Example Ltd';
$customer->email = 'billing@example.com';

$request = new AddRequest();
$request->record = $customer;
$write = $service->add($request)->writeResponse;

if ($write->status->isSuccess) {
    $customerId = $write->baseRef->internalId;
}
```

## Add a sales order with a custom field

```php
use NetSuite\Classes\AddRequest;
use NetSuite\Classes\CustomFieldList;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\SalesOrder;
use NetSuite\Classes\SalesOrderItem;
use NetSuite\Classes\SalesOrderItemList;
use NetSuite\Classes\StringCustomFieldRef;

$order = new SalesOrder();
$order->entity = new RecordRef();
$order->entity->internalId = $customerId;

$line = new SalesOrderItem();
$line->item = new RecordRef();
$line->item->internalId = $itemId;
$line->quantity = 2;
$order->itemList = new SalesOrderItemList();
$order->itemList->item = [$line];

$orderNumber = new StringCustomFieldRef();
$orderNumber->scriptId = 'custbody_order_number';
$orderNumber->value = 'WEB-1001';
$order->customFieldList = new CustomFieldList();
$order->customFieldList->customField = [$orderNumber];

$request = new AddRequest();
$request->record = $order;
$write = $service->add($request)->writeResponse;
```

## Upsert by external id

Creates the record, or updates the one with the same `externalId`.

```php
use NetSuite\Classes\Customer;
use NetSuite\Classes\UpsertRequest;

$customer = new Customer();
$customer->externalId = 'CUST-1001';
$customer->companyName = 'Example Ltd';

$request = new UpsertRequest();
$request->record = $customer;
$write = $service->upsert($request)->writeResponse;
```

## Fulfill a sales order

`initialize` prefills the fulfillment from the sales order; change what you need,
then `add` it. Cash sales and invoices work the same way.

```php
use NetSuite\Classes\AddRequest;
use NetSuite\Classes\InitializeRecord;
use NetSuite\Classes\InitializeRef;
use NetSuite\Classes\InitializeRefType;
use NetSuite\Classes\InitializeRequest;
use NetSuite\Classes\InitializeType;
use NetSuite\Classes\ItemFulfillmentPackage;
use NetSuite\Classes\ItemFulfillmentPackageList;

$initialize = new InitializeRecord();
$initialize->type = InitializeType::itemFulfillment;
$initialize->reference = new InitializeRef();
$initialize->reference->type = InitializeRefType::salesOrder;
$initialize->reference->internalId = $salesOrderId;

$request = new InitializeRequest();
$request->initializeRecord = $initialize;
$read = $service->initialize($request)->readResponse;

if ($read->status->isSuccess) {
    $fulfillment = $read->record;

    $package = new ItemFulfillmentPackage();
    $package->packageWeight = 1;
    $package->packageTrackingNumber = '1Z999AA10123456784';
    $fulfillment->packageList = new ItemFulfillmentPackageList();
    $fulfillment->packageList->package = [$package];

    $request = new AddRequest();
    $request->record = $fulfillment;
    $write = $service->add($request)->writeResponse;
}
```

## Errors

Business errors come back in the response status:

```php
if (!$write->status->isSuccess) {
    foreach ($write->status->statusDetail as $detail) {
        echo $detail->code, ': ', $detail->message, PHP_EOL;
    }
}
```

Authentication, throttling and connection errors throw `\SoapFault`
(`NetSuite\Rest\Exception\RestFault`, a subclass, on the REST transport).

## Resources

* [SuiteTalk Web Services](https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/set_22152129.html) in the Oracle NetSuite Help Center
* [SOAP to REST upgrade guide](https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/book_8110600984.html)
* [Issues](https://github.com/max-dernovyi/netsuite-php/issues) for bugs in this package
