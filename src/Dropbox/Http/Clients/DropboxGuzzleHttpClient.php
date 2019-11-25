<?php

namespace Kunnu\Dropbox\Http\Clients;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;
use Kunnu\Dropbox\Http\DropboxRawResponse;
use GuzzleHttp\Exception\BadResponseException;
use Kunnu\Dropbox\Exceptions\DropboxClientException;

/**
 * DropboxGuzzleHttpClient.
 */
class DropboxGuzzleHttpClient implements DropboxHttpClientInterface
{
    /**
     * GuzzleHttp client.
     *
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * Create a new DropboxGuzzleHttpClient instance.
     *
     * @param Client $client GuzzleHttp Client
     */
    public function __construct(Client $client = null)
    {
        //Set the client
        $this->client = $client ?: new Client();
    }

    /**
     * Send request to the server and fetch the raw response.
     *
     * @param  string $url     URL/Endpoint to send the request to
     * @param  string $method  Request Method
     * @param  string|resource|StreamInterface $body Request Body
     * @param  array  $headers Request Headers
     * @param  array  $options Additional Options
     *
     * @return \Kunnu\Dropbox\Http\DropboxRawResponse Raw response from the server
     *
     * @throws \Kunnu\Dropbox\Exceptions\DropboxClientException
     */
    public function send($url, $method, $body, $headers = [], $options = [])
    {
        //Create a new Request Object
        $request = new Request($method, $url, $headers, $body);

        try {
            //Send the Request
            $rawResponse = $this->client->send($request, $options);
        } catch (BadResponseException $e) {
            throw new DropboxClientException($e->getResponse()->getBody(), $e->getCode(), $e);
        } catch (RequestException $e) {
            $rawResponse = $e->getResponse();

            if (! $rawResponse instanceof ResponseInterface) {
                throw new DropboxClientException($e->getMessage(), $e->getCode());
            }
        }

        //Something went wrong
        if ($rawResponse->getStatusCode() >= 400) {
            throw new DropboxClientException($rawResponse->getBody());
        }

        if (array_key_exists('sink', $options)) {
            //Response Body is saved to a file
            $body = '';
        } else {
            //Get the Response Body
            $body = $this->getResponseBody($rawResponse);
        }

        $rawHeaders = $rawResponse->getHeaders();
        $httpStatusCode = $rawResponse->getStatusCode();

        //Create and return a DropboxRawResponse object
        return new DropboxRawResponse($rawHeaders, $body, $httpStatusCode);
    }

    /**
     * Send multiple requests to the server in parallel and fetch the raw response.
     *
     * @param  array $requests     An array of requests to send.
     *
     * @return \Kunnu\Dropbox\Http\DropboxRawResponse Raw response from the server
     *
     * @throws \Kunnu\Dropbox\Exceptions\DropboxClientException
     */
    public function send_multiple(&$requests)
    {
    	$requestArray = [];
		foreach($requests as $id=>$request)
		{
			$options = [];
			if (array_key_exists('options', $request))
				$options = $request['options'];

			$options['body'] = $request['body'];

			if (array_key_exists('headers', $request))
				$options['headers'] = $request['headers'];
			else
				$options['headers'] = [];

			$requestArray[$id] = $this->client->requestAsync($request['method'], $request['url'], $options);
		}

		try
		{
			$results = \GuzzleHttp\Promise\unwrap($requestArray);

			foreach ($results as $id=>$rawResponse)
			{
				$httpStatusCode = $rawResponse->getStatusCode();

				//Something went wrong
				if ($httpStatusCode >= 400)
					throw new DropboxClientException($rawResponse->getBody());

				$body = $rawResponse->getBody();
				$rawHeaders = $rawResponse->getHeaders();

				//Create and return a DropboxRawResponse object
				$requests[$id]['response'] = new DropboxRawResponse($rawHeaders, $body, $httpStatusCode);
			}
		}
		catch (Exception $e)
		{
			throw new DropboxClientException($e->getMessage(), $e->getCode());
		}
    }

    /**
     * Get the Response Body.
     *
     * @param string|\Psr\Http\Message\ResponseInterface $response Response object
     *
     * @return string
     */
    protected function getResponseBody($response)
    {
        //Response must be string
        $body = $response;

        if ($response instanceof ResponseInterface) {
            //Fetch the body
            $body = $response->getBody();
        }

        if ($body instanceof StreamInterface) {
            $body = $body->getContents();
        }

        return (string) $body;
    }
}
