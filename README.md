#woocommerce-fulfillment

"WooCommerce Fulfillment" extension for WooCommerce built for sustaincondoms.com.

## Config and Setup
This project is a static php plugin for WordPress and WooCommerce, which means that the WooCommerce plugin is a dependency.

- Ensure WooCommerce ~2.3.8 is installed.
- Download the [zip](https://github.com/barrel/woocommerce-fulfillment/archive/master.zip) file from github.
- Install the plugin by uploading the zip (Plugins > Add New > Upload).
- Activate the plugin.
- Configure the plugin (WooCommerce > Settings > Fulfillment) and populate all settings.


## Behavior and Implementation
1.  The plugin will automatically submit new orders for fulfillment at the point of sale.
2.  If the API/Fulfillment service is down, all new orders will be queued for fulfillment at a later time.
3.  Any orders queued for fulfillment (marked as *processing*) will automatically be submitted every hour.
4.  All completed orders will automatically check for updates to existing orders every hour.
5.  Completed orders with updates will add tracking information to the order.

## Full API Documentation for CA Short Fulfillment

Methods

| **Method** | SubmitOrder |
| --- | --- |
| **Type** | POST |
| **Path** | https://www.werecognizeyou.com/WebServices/Fulfillment/api/FulfillmentOrder/SubmitOrder |
| **Return Type** | OrderSubmitResponse |
| **Description** | Method to submit a full order, including any details |

**Request Object**

| **Object** | **DataType** | **Range** | **Description** |
| --- | --- | --- | --- |
| order | FulfillmentOrderRequest | 1-1 | The full Order object |

**Return Type** - OrderSubmitResponse

| **Parameter** | **DataType** | **Range** | **Description** |
| --- | --- | --- | --- |
| ServiceResult | ServiceResults | 0-4 | Standard service result confirming a successful service call |
| ValidationErrors | Array(String) | 0-MAX | A list of any validation errors on input fields |
| OrderResult | OrderSubmitResults | 1 | Result of the order submission process |
| OrderNumber | String | 0-10 | The resulting order number created in C.A. Short's system |

**Example Input**

[https://www.werecognizeyou.com/WebServices/Fulfillment/api/FulfillmentOrder/SubmitOrder](https://www.werecognizeyou.com/WebServices/Fulfillment/api/FulfillmentOrder/SubmitOrder)

**Example Output**

{"OrderNumber":"    83","OrderSubmitResult":0,"ServiceResult":0,"ValidationErrors":[]}

Methods

| **Method** | GetOrderHistory |
| --- | --- |
| **Type** | GET |
| **Path** | [https://www.werecognizeyou.com/WebServices/Fulfillment/api/FulfillmentOrder/GetOrderHistory](https://www.werecognizeyou.com/WebServices/Fulfillment/api/FulfillmentOrder/GetOrderHistory) |
| **Return Type** | OrderHistoryResponse |
| **Description** | Method to retrieve order history based on a date range |

**Parameters**

| **Parameter** | **DataType** | **Range** | **Description** |
| --- | --- | --- | --- |
| username | String | 1-30 | Service username |
| password | String | 1-30 | Service password |
| startDate | DateTime | N/A | Start date for the returned history |
| endDate | DateTime | N/A | End date for the returned history |

**Return Type** - OrderHistoryResponse

| **Parameter** | **DataType** | **Range** | **Description** |
| --- | --- | --- | --- |
| ServiceResult | ServiceResults | 0-4 | Standard service result confirming a successful service call |
| ValidationErrors | String() | 0-MAX | A list of any validation errors on input fields |
| Orders | Array(HistoryOrder) | 0-MAX | List of HistoryOrder objects for each Order returned |

**Example Input**

https://www.werecognizeyou.com/WebServices/Fulfillment/api/FulfillmentOrder/GetOrderHistory?username=XXXXXXX&password=XXXXXXX&startDate=2014-1-1&endDate=2014-6-6

**Example Output**

{"Orders":[{"OrderNumber":"    82","OrderDate":"2014-07-24T00:00:00","OrderDetailCount":1,"OrderDetailShippedCount":1,"Shipments":[]},{"OrderNumber":"    83","OrderDate":"2014-07-24T00:00:00","OrderDetailCount":1,"OrderDetailShippedCount":1,"Shipments":[]}],"ServiceResult":0,"ValidationErrors":[]}

Codes/Enumerations

**ServiceResults**

| **Value** | **Name** | **Description** |
| --- | --- | --- |
| 0 | Success | The service call completed successfully |
| 1 | General\_Failure | An error occurred during processing |
| 2 | Invalid\_Token | The token was invalid or is no longer tied to a user |
| 3 | Invalid\_API\_Key | The supplied key is invalid (Not used in all service calls) |
| 4 | Validation\_Error | A validation error was found in the input values |

**OrderSubmitResults**

| **Value** | **Name** | **Description** |
| --- | --- | --- |
| 0 | Success | The Order was created successfully |
| 1 | No\_Order\_Created | No error occurred, but an order was not created |
| 2 | General\_Error | An error occurred during processing |
| 3 | Order\_Already\_Exists | The reference order number already exists on another order |

**DiscountTypes**

| **Value** | **Name** | **Description** |
| --- | --- | --- |
| 0 | Percentage\_Discount | Percentage-based discount |
| 1 | Flat\_Discount | Flat amount discount |

Input/Return Objects

**FulfillmentOrderRequest**

| **Name** | **DataType** | **Range** | **Description** |
| --- | --- | --- | --- |
| Username | String | 1-30 | Username |
| Password | String | 1-30 | Password |
| Order | FulfillmentOrder | 1 | Object containing all order details |

**FulfillmentOrder**

| **Name** | **DataType** | **Range** | **Description** |
| --- | --- | --- | --- |
| OrderDate | DateTime | N/A | Date of the Order |
| Freight | Decimal | 0-10000 | Total shipping charges for the Order |
| MiscCharges | Decimal | 0-10000 | Miscellaneous charges to apply to the Order |
| SalesTax | Decimal | 0-10000 | Sales tax to apply to the Order (This should be calculated by C.A. Short) |
| DiscountType | DiscountTypes | 0-1 | Type of discount to apply |
| DiscountAmount | Decimal | 0-10000 | Amount of discount to apply |
| PurchaseOrderNumber | String | 0-22 | Purchase Order number if applicable |
| ReferenceOrderNumber | String | 0-80 | External Order number to tie both systems |
| Address | FulfillmentAddress | 1 | Address of the Order |
| Phone | FulfillmentPhone | 1 | Phone of the Order |
| OrderDetails | Array(FulfillmentOrderDetail) | 1-MAX | List of Order details |

**FulfillmentOrderDetail**

| **Name** | **DataType** | **Range** | **Description** |
| --- | --- | --- | --- |
| ItemNumber | String | 1-30 | Item number |
| Quantity | Integer | 1-10000 | Quantity of item sold |
| UnitPrice | Decimal | 0-10000 | MSRP of the selected item (single unit) |
| DiscountAmount | Decimal | 0-10000 | Percentage discount to apply to this single line |
| Freight | Decimal | 0-10000 | Shipping charges for this single line (typically use either line freight or order freight) |

**FulfillmentAddress**

| **Name** | **DataType** | **Range** | **Description** |
| --- | --- | --- | --- |
| Description | String | 1-60 | Address description |
| AttnContact | String | 1-60 | Address Attention-to |
| Line1 | String | 1-50 | Address line 1 |
| Line2 | String | 0-50 | Address line 2 |
| Line3 | String | 0-50 | Address line 3 |
| Line4 | String | 0-50 | Address line 4 |
| City | String | 1-30 | Address city |
| State | String | 2 | Address state |
| ZipCode | String | 1-10 | Address zip code |
| County | String | 1-30 | Address county |
| Country | String | 3 | Address country |
| EmailAddress | String | 1-60 | Email address |



**FulfillmentPhone**

| **Name** | **DataType** | **Range** | **Description** |
| --- | --- | --- | --- |
| Phone1 | String | 1-25 | Phone 1 |
| Phone2 | String | 0-25 | Phone 2 |
| Phone3 | String | 0-25 | Phone 3 |
| Phone4 | String | 0-25 | Phone 4 |

**HistoryOrder**

| **Name** | **DataType** | **Range** | **Description** |
| --- | --- | --- | --- |
| OrderNumber | String | 1-10 | Order number |
| OrderDate | DateTime | N/A | Order date |
| OrderDetailCount | Integer | 0-10000 | Count of all items on the order |
| OrderDetailShippedCount | Integer | 0-10000 | Count of all items already shipped |
| Shipments | Array(HistoryOrderShipment) | 0-MAX | List of shipments associated with this Order |
| ThirdPartyOrderNumber | String | 1-20 | Reference Order number |

**HistoryOrderShipment**

| **Name** | **DataType** | **Range** | **Description** |
| --- | --- | --- | --- |
| OrderDetailLineNumber | Integer | Int32 | Order line number |
| ShipDate | DateTime | N/A | Date of shipment |
| ServiceProvider | String | 0-10 | The shipping service provider |
| TrackingNumber | String | 0-50 | Tracking number of the shipment (if present) |

