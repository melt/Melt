<?php

/**
*@desc The http api namespace.
*/
class api_http {
    const HTTP_METHOD_GET = 0;
    const HTTP_METHOD_POST = 1;
    const HTTP_METHOD_HEAD = 2;

    /**
     * Unhooks the current request from the client by forcing the client
     * to close the connection and setting PHP to ignore souch an abort.
     * Note: Relies on a hack that might stop working in future versions.
     */
    public static function unhook_current_request() {
        api_misc::ob_reset();
        ignore_user_abort(true);
        header("Connection: close");
        header("Content-Encoding: none");
        header("Content-Length: 1");
        ob_start();
        echo "\n";
        ob_end_flush();
        flush();
        ob_end_clean();
    }

    /**
     * Encodes the key,value data with URL encoding. Does not support binary data.
     * @param array $data Key value data to encode.
     * @return array An array mapped like this: 0 => Content Type, 1 => Data.
     */
    public static function make_urlencoded_formdata($data) {
        $data = http_build_query($data);
        return array("application/x-www-form-urlencoded", $data);
    }

    /**
     * Encodes the key,value data as multipart/form-data. Supports binary data.
     * @param array $data Key value data to encode.
     * @return array An array mapped like this: 0 => Content Type, 1 => Data.
     */
    public static function make_multipart_formdata($data) {
        $boundary = api_string::random_hex_str(16);
        $encoded_data = "";
        // Encoding POST data as multipart/form-data. Otherwise
        foreach ($data as $key => $val) {
            $key = str_replace('"', '\"', $key);
            $encoded_data .= "--$boundary\r\n";
            $encoded_data .= "Content-Disposition: form-data; name=\"$key\"\r\n";
            $encoded_data .= "Content-Type: application/octet-stream; charset=UTF-8\r\n";
            $encoded_data .= "Content-Transfer-Encoding: binary\r\n\r\n";
            $encoded_data .= $val . "\r\n";
        }
        $encoded_data .= "--$boundary--\r\n";
        return array("multipart/form-data; boundary=" . $boundary, $encoded_data);
    }

    /**
     * Makes a HTTP request for the given URL and returns the data received.
     * @param string $url Absolute URL to send the HTTP request too.
     * @param HTTP_METHOD $method A api_http::HTTP_METHOD_XXX method for the request.
     * @param array $cookies An array of cookies to send with the request.
     * @param string $user_agent Specify something other than null to not use the default nanoMVC user agent.
     * @param boolean $include_common_headers Set to true to send headers assoicated with normal browsers to make the request look more natural.
     * @param array $contents Specify to send data with the POST request. It should contain an array mapped like this: 0 => Content Type, 1 => Data.
     * @param integer $timeout Time before request times out.
     * @return mixed The data and response headers returned by the request like this: 0 => Returned Data, 1 => Response Headers or an error code if the request failed.
     *               Error codes: -1 = Too many redirects (max 8), -2 = Request failed (connection timeout or malformed response)
     */
    public static function request($url, $method = api_http::HTTP_METHOD_GET, $cookies = array(), $user_agent = null, $include_common_headers = false, $contents = array(), $timeout = 5) {
        $methods = array(
            self::HTTP_METHOD_GET => "GET",
            self::HTTP_METHOD_POST => "POST",
            self::HTTP_METHOD_HEAD => "HEAD",
        );
        if (!isset($methods[$method]))
            throw new Exception("Unknown HTTP method! Not part of api_http::HTTP_METHOD_XXX.");
        $headers = array();
        // Write user agent.
        if ($user_agent === null)
            $user_agent = "nanoMVC/" . nmvc_version;
        $headers["User-Agent"] = $user_agent;
        // Include common client headers if requested.
        if ($include_common_headers) {
            $headers["Accept-language"] = "en";
            $headers["Accept"] = "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8";
            $headers["Accept-Language"] = "en-us,en;q=0.5";
            $headers["Accept-Charset"] = " ISO-8859-1,utf-8;q=0.7,*;q=0.7";
            $headers["Cache-Control"] = "max-age=0";
        }
        // Write cookies to cookie header.
        if (count($cookies) > 0) {
            foreach ($cookies as $k => &$v)
                $v = "$k=$v";
            $headers["Cookie"] = implode($cookies, "; ");
        }
        if ($method === self::HTTP_METHOD_POST && count($contents) == 2) {
            $headers["Content-Type"] = $contents[0];
            $content = $contents[1];
        } else
            $content = null;
        // Make request, max 8 redirects.
        for ($i = 0; $i < 8; $i++) {
            $response = self::raw_request($url, $methods[$method], $headers, $content, $timeout);
            if ($response === false)
                return -2;
            $status_code = $response[1];
            $return_headers = $response[3];
            if ($method !== self::HTTP_METHOD_POST && $status_code[0] == "3" && isset($return_headers["Location"])) {
                $url = $return_headers["Location"];
            } else {
                $data_blob = $response[4];
                return array($data_blob, $return_headers);
            }
        }
        return -1;
    }

    /**
     * Makes a RAW HTTP request and returns the result. This function does NOT follow redirects etc.
     * It only makes a single request using sockets. The reusult is stored in an array.
     * @param string $url URL to request. Supports http (default) and https with SSL extention.
     * @param string $method GET, POST, HEAD or any other method the server supports.
     * @param array $headers A key value array of headers you want to use with the request.
     * @param string $data The data you want to send with the request or null to not send data.
     * @param integer $timeout Specify to change the maximum time which PHP will wait for a connection to be established.
     * @return mixed array(http version, status code, reason phrase, headers (key => value mapped), data blob) or FALSE if connection or response parsing failed.
     */
    public static function raw_request($url, $method = "GET", $headers = array(), $data = null, $timeout = 5) {
        $parts = parse_url($url);
        $scheme = isset($parts['scheme'])? $parts['scheme']: 'http';
        if (!isset($parts['host']))
            throw new Exception("URL '$url' does not contain host!");
        $host = $parts['host'];
        $port = isset($parts['port'])? intval($parts['port']): 'http';
        $path = isset($parts['path'])? $parts['path']: '/';
        $query = isset($parts['query'])? '?' . $parts['query']: '';
        if ($scheme == 'http') {
            $default_port = 80;
            $sock_host = $host;
        } else if ($scheme == 'https') {
            $default_port = 443;
            $sock_host = "ssl://" . $host;
        } else
            throw new Exception("raw_request does not understand the protocol: " . $parts['scheme']);
        $port = isset($parts['port'])? $parts['port']: $default_port;
        $request_data = 
        $method . " " . $path . $query . " HTTP/1.1\r\n";
        if (!isset($headers["Host"])) {
            $headers["Host"] = $host;
            if ($port != 80)
                $headers["Host"] .=  ':' . $port;
        }
        if (!isset($headers["Connection"]))
            $headers["Connection"] = "Close";
        if (!isset($headers["Content-Length"]) && strlen($data) > 0)
            $headers["Content-Length"] = strlen($data);
        // Do not allow Accept-Encoding header to be overwritten as it controls
        // the internal decoding in raw_request().
        // raw_request() does not support compression.
        $headers["Accept-Encoding"] = "chunked, identity";
        foreach ($headers as $header => $value)
            $request_data .= "$header: " . $value . "\r\n";
        $request_data .= "\r\n" . $data;
        $fp = fsockopen($sock_host, $port, $errno, $errstr, $timeout);
        if (!$fp)
            return false;
        fwrite($fp, $request_data);
        $response = "";
        while (!feof($fp))
            $response .= fgets($fp, 1280);
        fclose($fp);
        $header_blob_length = strpos($response, "\r\n\r\n");
        if ($header_blob_length === false)
            return false;
        $header_blob = substr($response, 0, $header_blob_length + 2);
        $data_blob = substr($response, $header_blob_length + 4);
        if (preg_match('#(HTTP/1\..) ([^ ]+) ([^' . "\r\n" . ']+)' . "\r\n#im", $header_blob, $matches) == 0)
            return false;
        $http_version = $matches[1];
        $status_code = $matches[2];
        $reason_phrase = $matches[3];
        preg_match_all("#([^:\r\n]+): ([^\r\n]*)#", $header_blob, $matches, PREG_SET_ORDER, strlen($matches[0]));
        $headers = array();
        foreach ($matches as $match)
            $headers[$match[1]] = $match[2];
        // Convert chunked transfer encoding to identity if specified.
        $transfer_encoding = @$headers['Transfer-Encoding'];
        if ($transfer_encoding == "chunked") {
            $new_data_blob = "";
            $at = 0;
            while (true) {
                $next_newline = strpos($data_blob, "\r\n", $at);
                if ($next_newline === false)
                    break;
                $chunk_length = hexdec(substr($data_blob, $at, $next_newline - $at));
                if ($chunk_length == 0)
                    break;
                $new_data_blob .= substr($data_blob, $next_newline + 2, $chunk_length);
                $at = $next_newline + 2 + $chunk_length + 2;
            }
            $data_blob = $new_data_blob;
        }
        return array($http_version, $status_code, $reason_phrase, $headers, $data_blob);
    }
}

?>