<?php

namespace MimeMailParser;

use MimeMailParser\Exception\RuntimeException;

/**
 * Fast Mime Mail parser Class using PHP's MailParse Extension
 *
 * @author gabe@fijiwebdesign.com
 * @url http://www.fijiwebdesign.com/
 * @license http://creativecommons.org/licenses/by-sa/3.0/us/
 * @version $Id$
 */
class Parser
{

	/**
	 * PHP MimeParser Resource ID
	 */
	public $resource;

	/**
	 * A file pointer to email
	 */
	public $stream;

	/**
	 * A text of an email
	 */
	public $data;

	/**
	 * Parts array
	 */
	public $parts;

	/**
	 * Stream Resources for Attachments
	 */
	public $attachment_streams;

	/**
	 * Inialize some stuff
	 * @return
	 */
	public function __construct()
	{
		$this->attachment_streams = array();
	}

	/**
	 * Free the held resouces
	 * @return void
	 */
	public function __destruct()
	{
		// clear the email file resource
		if (is_resource($this->stream)) {
			fclose($this->stream);
		}
		// clear the MailParse resource
		if (is_resource($this->resource)) {
			mailparse_msg_free($this->resource);
		}
		// remove attachment resources
		foreach ($this->attachment_streams as $stream) {
			fclose($stream);
		}
	}

	/**
	 * Set the file path we use to get the email text
	 * @return Object MimeMailParser Instance
	 * @param $path Object
	 */
	public function setPath($path)
	{
		// should parse message incrementally from file
		$this->resource = mailparse_msg_parse_file($path);
		$this->stream = fopen($path, 'r');
		$this->parse();
		return $this;
	}

	/**
	 * Set the Stream resource we use to get the email text
	 * @return Object MimeMailParser Instance
	 * @param $stream Resource
	 */
	public function setStream($stream)
	{

		// streams have to be cached to file first
		if (get_resource_type($stream) == 'stream') {
			$tmp_fp = tmpfile();
			if ($tmp_fp) {
				while ( ! feof($stream)) {
					fwrite($tmp_fp, fread($stream, 2028));
				}
				fseek($tmp_fp, 0);
				$this->stream = & $tmp_fp;
			} else {
				throw new RuntimeException('Could not create temporary files for attachments. Your tmp directory may be unwritable by PHP.');
			}
			fclose($stream);
		} else {
			$this->stream = $stream;
		}

		$this->resource = mailparse_msg_create();
		// parses the message incrementally low memory usage but slower
		while ( ! feof($this->stream)) {
			mailparse_msg_parse($this->resource, fread($this->stream, 2082));
		}
		$this->parse();
		return $this;
	}

	/**
	 * Set the email text
	 * @return Object MimeMailParser Instance
	 * @param $data String
	 */
	public function setText($data)
	{
		$this->resource = mailparse_msg_create();
		// does not parse incrementally, fast memory hog might explode
		mailparse_msg_parse($this->resource, $data);
		$this->data = $data;
		$this->parse();
		return $this;
	}

	/**
	 * Parse the Message into parts
	 * @return void
	 * @private
	 */
	private function parse()
	{
		// Suppress annoying notices/warnings
		set_error_handler(function() { /* Nothing */ }, E_NOTICE|E_WARNING);

		$structure = mailparse_msg_get_structure($this->resource);
		$this->parts = array();
		foreach ($structure as $part_id) {
			$part = mailparse_msg_get_part($this->resource, $part_id);
			$this->parts[$part_id] = mailparse_msg_get_part_data($part);
		}

		restore_error_handler();
	}

	/**
	 * Retrieve the Email Headers
	 * @return Array
	 */
	public function getHeaders()
	{
		if (isset($this->parts[1])) {
			return $this->getPartHeaders($this->parts[1]);
		} else {
			throw new RuntimeException('Parser::setPath() or Parser::setText() must be called before retrieving email headers.');
		}
		return false;
	}

	/**
	 * Retrieve the raw Email Headers
	 * @return string
	 */
	public function getHeadersRaw()
	{
		if (isset($this->parts[1])) {
			return $this->getPartHeaderRaw($this->parts[1]);
		} else {
			throw new RuntimeException('Parser::setPath() or Parser::setText() must be called before retrieving email headers.');
		}
		return false;
	}

	/**
	 * Retrieve a specific Email Header
	 * @return String
	 * @param $name String Header name
	 */
	public function getHeader($name)
	{
		if (isset($this->parts[1])) {
			$headers = $this->getPartHeaders($this->parts[1]);
			if (isset($headers[$name])) {
				return $headers[$name];
			}
		} else {
			throw new RuntimeException('Parser::setPath() or Parser::setText() must be called before retrieving email headers.');
		}
		return false;
	}

	/**
	* Hack for duplicate headers which should not be duplicated
	*/
	public function pickOne( $header )
	{
		if( is_array( $header ) ) {
			return $header[0];
		}
		return $header;
	}

	/**
	 * Returns the report sections if top-level content type is multipart/report
	 * @return Mixed array Body or False if not found
	 */
	public function getReport() {
			$body = false;
            if ( isset($this->parts[1]) && $this->getPartContentType($this->parts[1]) === 'multipart/report' ) {
                $body = array();
                foreach ( $this->parts as $id => $part ) {
                    if ( preg_match('/^1\.[0-9]+$/', $id) ) {
						$headers = $this->getPartHeaders($part);

						$decoded_body = $this->decode($this->getPartBody($part), array_key_exists('content-transfer-encoding', $headers) ? $this->pickOne($headers['content-transfer-encoding']) : '');
						if ( $decoded_body === false ) {
							continue;
						}

						$content_type = $this->getPartContentType($part);
						$prefix = '';
						if ( $content_type !== 'text/plain' ) {
							$prefix =  "----------------------------------------------\n";
							$prefix .= ( $content_type . "\n" );
							$prefix .= "----------------------------------------------\n";
						}

						$body[] =array('body' => $prefix.$decoded_body,
						'encoding' => isset($part['content-charset']) ? $part['content-charset'] : '');
                    }
                }
            }
            return $body;
	}

	/**
	 * Returns the email message body in the specified format
	 * @return Mixed array Body or False if not found
	 * @param $type Object[optional]
	 */
	public function getMessageBody($type = 'text')
	{
		$body = false;
		$mime_types = array(
			'text' => array('text/plain','text','plain/text'), // add misspellings as a hack for some stupid emails
			'html' => array('text/html')
		);

		if (in_array($type, array_keys($mime_types))) {
			foreach ($this->parts as $part) {
				if ( in_array($this->getPartContentType($part), $mime_types[$type]) && $this->isInlineContent($part)) {
					$headers = $this->getPartHeaders($part);

					$decoded_body = $this->decode($this->getPartBody($part), array_key_exists('content-transfer-encoding', $headers) ? $this->pickOne($headers['content-transfer-encoding']) : '');
					if ( $decoded_body === false ) {
						$body[] = array(
							'body' => $type === 'text' ? "Error decoding message content\n" : "<div>Error decoding message content</div>",
							'encoding' => 'utf8'
						);
					}
					else {
						$body[] = array(
							'body' => $decoded_body,
							'encoding' => isset($part['content-charset']) ? $part['content-charset'] : ''
						);
					}
				}
			}
		} else {
			throw new RuntimeException('Invalid type specified for Parser::getMessageBody. "type" can either be text or html.');
		}

		return $body;
	}

	//inline but only support txt, html.
	public function isInlineContent($part)
	{
		$dis = $this->getPartContentDisposition($part);
		if ($dis)//ture
		{
			if ($dis == 'inline' || $dis == 'infile') // Add 'infile' as a hack for some stupid emails
			{
				return true;
				//maybe need it in the future.
				// if (isset($part['disposition-filename']) && isset($part['content-name']))
				// {
				// 	$filename = end(explode('.', $part['disposition-filename']));
				// 	$name = end(explode('.', isset($part['content-name']);
				// 	if ($filename == 'html' || $filename == 'htm' || $name == 'html' || $name == 'htm')))
				// 	{
				// 		return true;
				// 	}
				// 	else
				// 	{
				// 		return false;
				// 	}
				// }
				// else if(isset($part['disposition-filename']))
				// {
				// 	echo $part['disposition-filename'];
				// 	if (end(explode('.', $part['disposition-filename'])) == 'htm' || end(explode('.', $part['disposition-filename'])) == 'html' )
				// 	{
				// 		return true;
				// 	}
				// 	else
				// 	{
				// 		return false;
				// 	}
				// }
				// else if (isset($part['content-name']))
				// {
				// 	if (end(explode('.', $part['content-name'])) == 'html')
				// 	{
				// 		return true;
				// 	}
				// 	else
				// 	{
				// 		return false;
				// 	}
				// }
				// else
				// {
				// 	return true;
				// }
			}
			else
			{
				return false;
			}
		}
		else
		{
			return true;
		}
	}


	/**
	 * get the headers for the message body part.
	 * @return Array
	 * @param $type Object[optional]
	 */
	public function getMessageBodyHeaders($type = 'text')
	{
		$headers = false;
		$mime_types = array(
			'text' => 'text/plain',
			'html' => 'text/html'
		);
		if (in_array($type, array_keys($mime_types))) {
			foreach ($this->parts as $part) {
				if ($this->getPartContentType($part) == $mime_types[$type]) {
					$headers = $this->getPartHeaders($part);
				}
			}
		} else {
			throw new RuntimeException('Invalid type specified for Parser::getMessageBody. "type" can either be text or html.');
		}
		return $headers;
	}

	/**
	 * Returns the attachments contents in order of appearance
	 * @return Array
	 * @param $type Object[optional]
	 */
	public function getAttachments()
	{
		$attachments = array();
		$dispositions = array("attachment", "inline");
		$content_types = array(
			'application/octet-stream' => null,
			'application/zip' => 'zip',
			'text/calendar' => 'ics',
			'application/pdf' => 'pdf',
			'image/jpeg' => 'jpg',
			'image/gif' => 'gif',
			'image/png' => 'png',
			'image/bmp' => 'bmp',
			'video/3gpp' => '3gp',
			'audio/3gpp' => '3gp',
			'video/3gpp2' => '3g2',
			'audio/3gpp2' => '3g2',
			'video/mp4' => 'mp4',
			'audio/mp4' => 'mp4',
			'audio/wav' => 'wav',
			'audio/mpeg' => 'mp3'
		);

		$mime_encrypted = null;
		$mime_signed = null;
		// fix MS/Exchange garbling
		$garbled_mime_encrypt = false;

		foreach ($this->parts as $part_id => $part) {

			$headers = $this->getPartHeaders($part);

			if (isset($part['content-type'])) {

				if (isset($part['content-protocol']) && !$mime_signed && !$mime_encrypted) {
					if ($part['content-type'] === 'multipart/encrypted' && $part['content-protocol'] === 'application/pgp-encrypted') {
						$mime_encrypted = $part_id;
					}
					if ($part['content-type'] === 'multipart/signed' && $part['content-protocol'] === 'application/pgp-signature') {
						$mime_signed = $part_id;
					}
				}

				if ($part['content-type'] === 'application/pgp-encrypted' && !$mime_encrypted) {
				    $body = $this->getPartBody($part);
				    $transferEncoding = array_key_exists('content-transfer-encoding', $headers) ? $this->pickOne($headers['content-transfer-encoding']) : '';
					$decoded_body = $this->decode($body, $transferEncoding);

					if (trim($decoded_body) === 'Version: 1') {
						// next part might be the pgp mime message
						$garbled_mime_encrypt = explode('.', $part_id);
						$garbled_mime_encrypt[count($garbled_mime_encrypt) - 1]++;
						$garbled_mime_encrypt = implode('.', $garbled_mime_encrypt);
					}
				}

				if ($mime_encrypted || $mime_signed || $garbled_mime_encrypt) {

					$disposition = null;

					// PGP encrypted
					if ( ($mime_encrypted . '.2' === $part_id || $garbled_mime_encrypt === $part_id) &&
						$part['content-type'] === 'application/octet-stream') {
						$attachments = [];
						$disposition = 'pgp-encrypted';
						$content = $this->getAttachmentStream($part);
					}
					// PGP signed
					else if ($mime_signed . '.2' === $part_id && $part['content-type'] === 'application/pgp-signature') {
						$disposition = 'pgp-signature';
						$content = $this->getAttachmentStream($part);
					}
					else if ($mime_signed . '.1' === $part_id) {
						$disposition = 'pgp-signed';
						$content = $this->getStream($this->getPartRaw($part));
					}

					if ($disposition) {
						$attachments[] = new Attachment(
							$disposition,
							$this->getPartContentType($part),
							$content,
							$disposition,
							$headers
						);

						if ($disposition === 'pgp-encrypted') {
							// We are done, no need to parse further
							break;
						}
						else {
							continue;
						}
					}
				}
			}

			// Regular attachments
			$disposition = $this->getPartContentDisposition($part);
            if ((in_array($disposition, $dispositions) && (isset($part['content-name']) || isset($part['name']) || isset($part['disposition-filename']))) ||
                (isset($part['content-type']) && array_key_exists($part['content-type'], $content_types))
            ) {
				$default_name = 'default';
				if ( isset($part['content-type']) && $part['content-type'] == 'text/calendar') {
					$default_name = 'calendar.ics';
				}

				// Attachment naming priority list
				$name = ( isset($part['disposition-filename']) ) ? $part['disposition-filename'] : '';
				$name = ( strlen($name) === 0 && isset($part['content-name']) ) ? $part['content-name'] : $name;
				$name = ( strlen($name) === 0 && isset($part['name']) ) ? $part['name'] : $name;
				$name = ( strlen($name) === 0 && isset($part['content-location']) ) ? $part['content-location'] : $name;
				// Content name
				if ( strlen($name) === 0 && isset($part['content-location']) ) {
					$path = parse_url($part['content-location'], PHP_URL_PATH);
					setlocale(LC_ALL, 'en_US.utf8');
					$name = basename($path);
				}
				// Content ID
				$name = ( strlen($name) === 0 && isset($part['content-id']) ) ? str_replace('>','',str_replace('<','',$part['content-id'])) : $name;
				// Default
                $name = strlen($name) === 0 ? $default_name : $name;

				// Guess at missing disposition
				if (!$disposition) {
					if (isset($headers['content-id']) || isset($headers['content-location'])) {
						$disposition = 'inline';
					}
					else {
						$disposition = 'attachment';
					}
					$headers['content-disposition'] = $disposition;
				}

				$attachments[] = new Attachment(
					$name,
					$this->getPartContentType($part),
					$this->getAttachmentStream($part),
					$disposition,
					$headers
				);
			}
		}
		return $attachments;
	}

	/**
	 * Return the Headers for a MIME part
	 * @return Array
	 * @param $part Array
	 */
	protected function getPartHeaders($part)
	{
		if (isset($part['headers'])) {
			return $part['headers'];
		}
		return false;
	}

	/**
	 * Return the ContentType of the MIME part
	 * @return String
	 * @param $part Array
	 */
	protected function getPartContentType($part)
	{
		if (isset($part['content-type'])) {
			return $part['content-type'];
		}
		return false;
	}

	/**
	 * Return the Content Disposition
	 * @return String
	 * @param $part Array
	 */
	protected function getPartContentDisposition($part)
	{
		if (isset($part['content-disposition'])) {
			return $part['content-disposition'];
		}
		return false;
	}

	/**
	 * Retrieve the raw Header of a MIME part
	 * @return String
	 * @param $part Object
	 */
	protected function getPartHeaderRaw($part)
	{
		return $this->getData($part['starting-pos'], $part['starting-pos-body']);
	}

	/**
	 * Retrieve the Body of a MIME part
	 * @return String
	 * @param $part Object
	 */
	protected function getPartBody($part)
	{
		return $this->getData($part['starting-pos-body'], $part['ending-pos-body']);
	}

	protected function getPartRaw($part)
	{
		return $this->getData($part['starting-pos'], $part['ending-pos-body']);
	}

	protected function getData($start, $end)
	{
		if ($end <= $start) {
			return '';
		}

		if ($this->stream) {
			fseek($this->stream, $start, SEEK_SET);
			return fread($this->stream, $end - $start);
		} else if ($this->data) {
			return substr($this->data, $start, $end - $start);
		} else {
			throw new RuntimeException('Parser::setPath() or Parser::setText() must be called before retrieving email parts.');
		}
	}

	protected function getStream($data)
	{
		$temp_fp = tmpfile();
		if ($temp_fp) {
			fwrite($temp_fp, $data, strlen($data));
			fseek($temp_fp, 0, SEEK_SET);
		} else {
			throw new RuntimeException('Could not create temporary files for attachments. Your tmp directory may be unwritable by PHP.');
		}
		return $temp_fp;
	}

	/**
	 * Read the attachment Body and save temporary file resource
	 * @return String Mime Body Part
	 * @param $part Array
	 */
	protected function getAttachmentStream($part)
	{
		$temp_fp = tmpfile();

		$encoding = array_key_exists('content-transfer-encoding', $part['headers']) ? $this->pickOne($part['headers']['content-transfer-encoding']) : '';

		if ($temp_fp) {
			if ($this->stream) {
				$start = $part['starting-pos-body'];
				$end = $part['ending-pos-body'];
				fseek($this->stream, $start, SEEK_SET);
				$len = $end - $start;
				$written = 0;
				$write = 2028;
				while ($written < $len) {
                    if (($written + $write < $len)) {
                        $write = $len - $written;
                    } else if ($len < $write) {
                        $write = $len;
                    }
					$attachment = fread($this->stream, $write);
					fwrite($temp_fp, $this->decode($attachment, $encoding));
					$written += $write;
				}
			} else if ($this->data) {
				$attachment = $this->decode($this->getPartBody($part), $encoding);
				fwrite($temp_fp, $attachment, strlen($attachment));
			}
			fseek($temp_fp, 0, SEEK_SET);
		} else {
			throw new RuntimeException('Could not create temporary files for attachments. Your tmp directory may be unwritable by PHP.');
		}
		return $temp_fp;
	}

	/**
	 * Decode the string depending on encoding type.
	 * @return String the decoded string.
	 * @param $encodedString    The string in its original encoded state.
	 * @param $encodingType     The encoding type from the Content-Transfer-Encoding header of the part.
	 */
	private function decode($encodedString, $encodingType)
	{
		if (strtolower($encodingType) == 'base64') {
			return base64_decode($encodedString);
		} else if (strtolower($encodingType) == 'quoted-printable') {
			return quoted_printable_decode($encodedString);
		} else {
			return $encodedString;
		}
	}
}
