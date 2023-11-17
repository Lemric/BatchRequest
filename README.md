# Lemric Batch Request for Symfony
## Installing
Install from composer :
```
composer require lemric/batch-request
```
# Batch Requests

Send a single HTTP request that contains multiple API calls.
Independent operations are processed in parallel, while dependent operations are processed sequentially.
When all operations are complete, a consolidated response is returned to you and the HTTP connection is closed.

The order of the responses matches the order of the operations in the request.
You should process the responses accordingly to determine which operations were successful and which should be retried in a subsequent operation.

### Batch Request
A batch request takes a JSON object consisting of an array of your requests. It returns an array of logical HTTP responses represented as JSON arrays.
Each response has a status code, an optional header array, and an optional body (which is a JSON-encoded string).

To make a batch request, send a POST request to an endpoint where the batch parameter is your JSON object.

```POST /batch```

#### Sample Batch Request

In this example, we will get information about two Pages that our application manages.

Formatted for readability.

```
curl -X POST --location 'http://localhost:8282/batch'
   --header 'Content-Type: application/json'
   --data '[
       {
          "method":"GET",
          "relative_url":"url"
        },  
        {
          "method":"GET",
          "relative_url":"url",
        }
   ], 
    "include_headers": true'
```

Once all operations are completed, a response is sent with the result of each operation.
Because the headers returned can sometimes be much larger than the actual API response, you may want to remove them for efficiency.
To include headers, remove the include_headers parameter or set it to true.

#### Sample Response

The body field contains a string encoded JSON object:

```
[
  {
    "code": 200,
    "body": "{
      \"name\": \"Page A Name\",
      \"id\": \"1\"
      }"
  },
  {
    "code": 200,
    "body": "{
      \"name\": \"Page B Name\",
      \"id\": \"1\"
      }"
  }
]
```

### Complex Batch Requests

It is possible to combine operations that would normally use different HTTP methods into a single batch request.
While GET and DELETE operations can only have a relative_url and a method field, POST and PUT operations can have an optional body field.
The body should be formatted as a raw HTTP POST string, similar to a URL query string.

#### Sample Request

The following example deletes an object and then creates the new object in a single operation:
```
curl -X POST /batch
   --header 'Content-Type: application/json'
   --data '[
       {
          "method":"DELETE",
          "relative_url":"url/{id}"
        },  
        {
          "method":"POST",
          "relative_url":"url",
          "body": {"id": "1", "message": "First post!"}
        }
   ], 
    "include_headers": false'
```

### Errors

It is possible that one of your requested operations will fail. 
This could be because you don't have permission to perform the requested operation. 
The response is similar to the standard API, but encapsulated in batch response syntax:

```
[
    { "code": 403,
      "headers": [
          {"name":"WWW-Authenticate", "value":"OAuth…"},
          {"name":"Content-Type", "value":"text/javascript; charset=UTF-8"} ],
      "body": "{\"error\":{\"type\":\"OAuthException\", … }}"
    }
]
```

Other requests in the batch should still complete successfully and will be returned as normal with a 200 status code.

### Timeouts

Large or complex batches may timeout if it takes too long to complete all the requests in the batch. 
In such a case, the result is a partially completed batch. In a partially-completed batch, requests that complete successfully will return normal output with status code 200. 
Responses to requests that do not succeed will be null. You can retry any request that fails.

### Using Multiple Access Tokens

Individual requests in a single batch request can specify their own access tokens as query string or form post parameters. In this case, the top-level access token is considered a fallback token and will be used if an individual request does not explicitly specify an access token.

This can be useful if you want to query the API using multiple different user tokens, or if some of your calls need to be made using an application access token.

You must include an access token as a top-level parameter, even if each individual request contains its own token.

### Upload Binary Data

You can upload multiple binary items as part of a batch call. To do this, you must add all binary items to your request as multipart/mime attachments, and each operation must reference its binary items using the attached_files property in the operation. 
The attached_files property can take a comma-separated list of attachment names as its value.

The following example shows how to upload 2 photos in a single batch call:

```
curl 
     -F 'access_token=…' \
     -F 'batch=[{"method":"POST","relative_url":"me/photos","body":"message=My cat photo","attached_files":"file1"},{"method":"POST","relative_url":"me/photos","body":"message=My dog photo","attached_files":"file2"},]' \
     -F 'file1=@cat.gif' \
     -F 'file2=@dog.jpg' \
    /batch
```