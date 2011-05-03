<?php
	
	/**
	 * @package libs
	 */
	
	/**
	 * Represents an email template.
	 */
	class EmailBuilderEmail {
		/**
		 * Accepts an array of template IDs and deletes them all.
		 * @param array $items
		 */
		static public function deleteAll(array $items) {
			$result = array();
			
			foreach ($items as $id) {
				$result[] = self::load($id)->delete();
			}
			
			return array_sum($result) == count($result);
		}
		
		/**
		 * Does a particular template exist?
		 * @param integer $id
		 */
		static public function exists($id) {
			$data = Symphony::Database()->fetch(sprintf("
				SELECT
					e.*
				FROM
					`tbl_etf_emails` AS e
				WHERE
					e.id = %d
				",
				$id
			));
			
			return isset($data[0]['id']);
		}
		
		/**
		 * Load an email object from database.
		 * @param integer $id
		 */
		static public function load($id) {
			$data = Symphony::Database()->fetch(sprintf("
				SELECT
					e.*
				FROM
					`tbl_etf_emails` AS e
				WHERE
					e.id = %d
				",
				$id
			));
			$overrides = Symphony::Database()->fetch(sprintf("
				SELECT
					o.*
				FROM
					`tbl_etf_overrides` AS o
				WHERE
					o.email_id = %d
				ORDER BY
					o.sortorder ASC
				",
				$id
			));
			
			$email = new self();
			$email->setData($data[0]);
			$email->setOverrides($overrides);
			
			return $email;
		}
		
		protected $data;
		protected $errors;
		protected $overrides;
		
		public function __construct() {
			$this->data = (object)array();
			$this->errors = (object)array();
			$this->overrides = array();
		}
		
		/**
		 * Access the internal template data.
		 */
		public function data() {
			return $this->data;
		}
		
		/**
		 * Access the internal validation errors.
		 */
		public function errors() {
			return $this->errors;
		}
		
		/**
		 * Access the internal override data.
		 */
		public function overrides() {
			return $this->overrides;
		}
		
		public function countLogs() {
			return 0;
		}
		
		/**
		 * Delete this email template.
		 */
		public function delete() {
			$result = array();
			
			$result[] = Symphony::Database()->query(sprintf("
				DELETE FROM
					`tbl_etf_emails`
				WHERE
					`id` = %d
				",
				$this->data->id
			));
			
			$result[] = Symphony::Database()->query(sprintf("
				DELETE FROM
					`tbl_etf_logs`
				WHERE
					`email_id` = %d
				",
				$this->data->id
			));
			
			$result[] = Symphony::Database()->query(sprintf("
				DELETE FROM
					`tbl_etf_overrides`
				WHERE
					`email_id` = %d
				",
				$this->data->id
			));
			
			return array_sum($result) == count($result);
		}
		
		/**
		 * Fetch the email object, ready to be sent.
		 * @param integer $entry_id Use this entry to build the email.
		 */
		public function fetch($entry_id) {
			$url = sprintf(
				'%s?eb-entry=%d',
				$this->getPageURL(),
				$entry_id
			);
			
			// Fetch page:
			$ch = curl_init();
			curl_setopt_array($ch, array(
				CURLOPT_URL				=> $url,
				CURLOPT_TIMEOUT			=> 10,
				CURLOPT_HEADER			=> false,
				CURLOPT_FOLLOWLOCATION	=> true,
				CURLOPT_RETURNTRANSFER	=> true,
				CURLOPT_USERAGENT		=> 'Email Builder/1.0'
			));
			$html = curl_exec($ch);
			$info = curl_getinfo($ch);
			curl_close($ch);
			
			// Check for invalid response:
			switch ($info['http_code']) {
				case 200:
				case 301:
				case 302:
					break;
				default:
					throw new Exception(sprintf(
						"Unable to load template '%s' status code '%d' returned.",
						$url, $info['http_code']
					));
			}
			
			$email = new EmailBuilderEmailResult();
			$email->body()->html = $html;
			$email->headers()->subject = $this->data->subject;
			$email->headers()->sender_name = $this->data->sender_name;
			$email->headers()->sender_email_address = $this->data->sender_address;
			$email->headers()->recipient_address = $this->data->recipient_address;
			
			/**
			 * Allow email to be tweaked before being sent.
			 *
			 * @delegate BeforeSendEmail
			 * @param string $context
			 * '/extension/emailbuilder/'
			 * @param object $email
			 * @param object $result
			 * @param string $url
			 */
			Symphony::ExtensionManager()->notifyMembers(
				'BeforeSendEmail',
				'/extension/emailbuilder/',
				array(
					'email'			=> $this,
					'result'		=> $email,
					'url' 			=> $url
				)
			);
			
			return $email;
		}
		
		/**
		 * Get the URL of the template page.
		 */
		public function getPageURL() {
			$page = Symphony::Database()->fetchRow(0, sprintf("
				SELECT
					p.path,
					p.handle
				FROM
					`tbl_pages` as p
				WHERE
					p.id = %d
				",
				$this->data->page_id
			));
			
			$path = trim(rtrim($page['path'], '/') . '/' . $page['handle'], '/');
			
			return rtrim(sprintf('%s/%s', URL, $path), '/');
		}
		
		/**
		 * Set the template data.
		 * @param array|object $data
		 */
		public function setData($data) {
			$this->data = (object)array();
			
			foreach ($data as $key => $value) {
				$this->data->{$key} = $value;
			}
		}
		
		/**
		 * Set the template override data.
		 * @param array $data
		 */
		public function setOverrides(array $overrides) {
			$this->overrides = array();
			
			foreach ($overrides as $order => $override) {
				$this->overrides[$order] = new EmailBuilderOverride($override);
				$this->overrides[$order]->data()->sortorder = $order;
			}
		}
		
		/**
		 * Save the template.
		 */
		public function save() {
			$result = array();
			$fields = array(
				'id'				=> null,
				'page_id'			=> null,
				'name'				=> null,
				'subject'			=> null,
				'sender_name'		=> null,
				'sender_address'	=> null,
				'recipient_address'	=> null,
				'send_plain_text'	=> null,
				'send_attachments'	=> null
			);
			
			foreach ($fields as $key => $value) {
				if (!isset($this->data->{$key})) continue;
				
				$fields[$key] = $this->data->{$key};
			}
			
			$result[] = Symphony::Database()->insert($fields, 'tbl_etf_emails', true);
			
			if (!isset($this->data->id)) {
				$this->data->id = Symphony::Database()->getInsertID();
			}
			
			// Delete old overrides:
			$result[] = Symphony::Database()->query(sprintf("
				DELETE FROM
					`tbl_etf_overrides`
				WHERE
					`email_id` = %d
				",
				$this->data->id
			));
			
			// Insert new overrides:
			foreach ($this->overrides as $override) {
				$override->data()->email_id = $this->data->id;
				$result[] = $override->save();
			}
			
			return array_sum($result) == count($result);
		}
		
		/**
		 * Validate the template and populate the error object.
		 */
		public function validate() {
			$this->errors = new StdClass();
			$valid = true;
			
			if (!isset($this->data->name) || trim($this->data->name) == '') {
				$this->errors->name = __('Name must not be empty.');
				$valid = false;
			}
			
			if (!isset($this->data->subject) || trim($this->data->subject) == '') {
				$this->errors->subject = __('Subject must not be empty.');
				$valid = false;
			}
			
			if (!isset($this->data->sender_name) || trim($this->data->sender_name) == '') {
				$this->errors->sender_name = __('Sender Name must not be empty.');
				$valid = false;
			}
			
			if (!isset($this->data->sender_address) || trim($this->data->sender_address) == '') {
				$this->errors->sender_address = __('Sender Address must not be empty.');
				$valid = false;
			}
			
			if (!isset($this->data->recipient_address) || trim($this->data->recipient_address) == '') {
				$this->errors->recipient_address = __('Recipient Address must not be empty.');
				$valid = false;
			}
			
			if (!isset($this->data->page_id) || trim($this->data->page_id) == '') {
				$this->errors->page_id = __('You must choose a template page.');
				$valid = false;
			}
			
			foreach ($this->overrides as $order => $override) {
				$valid = (
					$override->validate()
						? $valid
						: false
				);
			}
			
			return $valid;
		}
	}
	
?>