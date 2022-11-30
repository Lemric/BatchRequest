# Lemric Batch Request for laravel
## Installing
Install from composer :
```
composer require lemric/batch-request
We make this package with Auto Discovery, but you can add manual :
```
# service provider :
Lemric\BatchRequest\BatchServiceProvider::class

## Usage
Send request to /batch with raw body content:
```json
REQUEST:
[
  {
    "method": "GET",
    "relative_url": "/getSome",
    "content-type": "application/x-www-form-urlencoded",
    "body": "userId=1"
  },
  {
    "method": "POST",
    "relative_url": "/saveSome"
    "body": {
        "itemId": 1,
        "name": "test"
    }
  }
]
```

```json
RESPONSE:
[
    {
        "code": 200,
        "body": []
    },
    {
        "code": 200,
        "body": []
    }
]
```

## Include headers
To include header information, remove the include_headers parameter or set it to true.

## Bearer token
The authorization token is passed directly to batch, which will automatically use it in every request