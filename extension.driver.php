<?php
	
	/**
	 * @package emailbuilder
	 */
	
	require_once TOOLKIT . '/class.sectionmanager.php';
	require_once TOOLKIT . '/class.entrymanager.php';
	require_once dirname(__FILE__) . '/libs/class.email.php';
	require_once dirname(__FILE__) . '/libs/class.emailiterator.php';
	require_once dirname(__FILE__) . '/libs/class.emailresult.php';
	require_once dirname(__FILE__) . '/libs/class.override.php';
	
	/**
	 * Email creation and sending API.
	 */
	class Extension_EmailBuilder extends Extension {
		/**
		 * Extension information.
		 */
		public function about() {
			return array(
				'name'			=> 'Email Builder',
				'version'		=> '0.1',
				'release-date'	=> '2011-05-03',
				'author'		=> array(
					'name'			=> 'Rowan Lewis',
					'website'		=> 'http://rowanlewis.com/',
					'email'			=> 'me@rowanlewis.com'
				)
			);
		}
		
		/**
		 * Cleanup installation.
		 */
		public function uninstall() {
			Symphony::Configuration()->remove('emailbuilder');
			Symphony::Database()->query("DROP TABLE `tbl_etf_emails`");
			Symphony::Database()->query("DROP TABLE `tbl_etf_overrides`");
			Symphony::Database()->query("DROP TABLE `tbl_etf_logs`");
		}
		
		/**
		 * Create tables and configuration.
		 */
		public function install() {
			$drop = array();
			
			try {
				Symphony::Database()->query("
					CREATE TABLE IF NOT EXISTS `tbl_etf_emails` (
						`id` int(11) unsigned not null auto_increment,
						`html_page_id` int(11) not null,
						`text_page_id` int(11) not null,
						`xml_page_id` int(11) not null,
						`name` varchar(255) not null,
						`subject` text not null,
						`sender_name` text not null,
						`sender_address` text not null,
						`recipient_address` text not null,
						PRIMARY KEY (`id`),
						KEY `html_page_id` (`page_id`),
						KEY `text_page_id` (`text_page_id`),
						KEY `xml_page_id` (`xml_page_id`)
					) ENGINE=MyISAM DEFAULT CHARSET=utf8;
				");
				$drop[] = 'tbl_etf_emails';
				
				Symphony::Database()->query("
					CREATE TABLE IF NOT EXISTS `tbl_etf_overrides` (
						`id` int(11) not null auto_increment,
						`email_id` int(11) not null,
						`expression` text not null,
						`sortorder` int(11) not null default 0,
						`subject` text,
						`sender_name` text,
						`sender_address` text,
						`recipient_address` text,
						PRIMARY KEY (`id`),
						KEY `email_id` (`email_id`)
					) ENGINE=MyISAM DEFAULT CHARSET=utf8;
				");
				$drop[] = 'tbl_etf_overrides';
				
				Symphony::Database()->query("
					CREATE TABLE IF NOT EXISTS `tbl_etf_logs` (
						`id` int(11) NOT NULL auto_increment,
						`email_id` int(11) not null,
						`entry_id` int(11) NOT NULL,
						`date` datetime NOT NULL,
						`success` enum('yes','no') not null,
						`sender_name` text,
						`sender_address` text,
						`recipient_address` text,
						`subject` text,
						`message` text,
						PRIMARY KEY (`id`),
						KEY `email_id` (`email_id`),
						KEY `entry_id` (`entry_id`),
						KEY `date` (`date`)
					) ENGINE=MyISAM DEFAULT CHARSET=utf8;
				");
				
				Symphony::Configuration()->set('navigation_group', __('Blueprints'), 'emailbuilder');
				Administration::instance()->saveConfig();
				
				return true;
			}
			
			catch (DatabaseException $e) {
				foreach ($drop as $table) {
					Symphony::Database()->query("DROP TABLE `{$table}`");
				}
				
				throw $e;
			}
		}
		
		/**
		 * Listen for these delegates.
		 */
		public function getSubscribedDelegates() {
			return array(
				array(
					'page' => '/system/preferences/',
					'delegate' => 'AddCustomPreferenceFieldsets',
					'callback' => 'viewPreferences'
				),
				array(
					'page' => '/system/preferences/',
					'delegate' => 'Save',
					'callback' => 'actionsPreferences'
				),
				array(
					'page' => '/extension/emailbuilder/',
					'delegate' => 'AppendAttachmentHandler',
					'callback' => 'appendAttachmentHandler'
				),
				array(
					'page' => '/extension/emailbuilder/',
					'delegate' => 'AppendPlainTextHandler',
					'callback' => 'appendPlainTextHandler'
				),
				array(
					'page' => '/extension/emailbuilder/',
					'delegate' => 'BeforeSendEmail',
					'callback' => 'beforeSendEmail'
				)
			);
		}
		
		/**
		 * Add navigation items.
		 */
		public function fetchNavigation() {
			$group = $this->getNavigationGroup();
			
			return array(
				array(
					'location'	=> $group,
					'name'		=> 'Emails',
					'link'		=> '/emails/'
				),
				array(
					'location'	=> $group,
					'name'		=> 'Email',
					'link'		=> '/email/',
					'visible'	=> 'no'
				),
				array(
					'location'	=> $group,
					'name'		=> 'Email Logs',
					'link'		=> '/logs/',
					'visible'	=> 'no'
				),
				array(
					'location'	=> $group,
					'name'		=> 'Email Preview',
					'link'		=> '/preview/',
					'visible'	=> 'no'
				)
			);
		}
		
		/**
		 * Append the 'Follow attachment links' option.
		 * Parses HTML link elements and treats the href as an attachment:
		 * <link rel="attachment" href="workspace/uploads/document.pdf" />
		 * @todo Move this to a separate extension.
		 * @param array $context
		 */
		public function appendAttachmentHandler($context) {
			$context['options'][] = array(
				'follow_attachment_links', $context['type'] == 'follow_links', __('Follow attachment links: &lt;link rel="attachment" /&gt;')
			);
		}
		
		/**
		 * Append the 'Intelligently remove HTML' option.
		 * @todo Move this to a separate extension.
		 * @param array $context
		 */
		public function appendPlainTextHandler($context) {
			$context['options'][] = array(
				'strip_html', $context['type'] == 'strip_html', __('Intelligently remove HTML')
			);
		}
		
		/**
		 * Search for attachments and/or strip HTML from message.
		 * @todo Move this to a separate extension.
		 * @param array $context
		 */
		public function beforeSendEmail($context) {
			$email = $context['email'];
			$result = $context['result'];
			
			if ($email->data()->send_attachments == 'follow_attachment_links') {
				$html = new DOMDocument();
				$html->loadHTML($result->body()->html);
				$xpath = new DOMXPath($html);
				
				foreach ($xpath->query('//link[@rel = "attachment"]') as $node) {
					$result->addAttachment($node->getAttribute('href'));
				}
			}
			
			if ($email->data()->send_plain_text == 'strip_html') {
				$result->body()->text = trim(strip_tags($result->body()->html));
			}
		}
		
	/*-------------------------------------------------------------------------
		Preferences:
	-------------------------------------------------------------------------*/
		
		
		/**
		 * True when no navigation group has been specified.
		 */
		protected $missing_navigation_group;
		
		/**
		 * Get the name of the desired navigation group.
		 */
		public function getNavigationGroup() {
			if ($this->missing_navigation_group === true) return null;
			
			return Symphony::Configuration()->get('navigation_group', 'emailbuilder');
		}
		
		/**
		 * Get a list of available navigation groups.
		 */
		public function getNavigationGroups() {
			$sectionManager = new SectionManager(Symphony::Engine());
			$sections = $sectionManager->fetch(null, 'ASC', 'sortorder');
			$options = array();
			
			if (is_array($sections)) foreach ($sections as $section) {
				$options[] = $section->get('navigation_group');
			}
			
			$options[] = __('Blueprints');
			$options[] = __('System');
			
			return array_unique($options);
		}
		
		/**
		 * Validate preferences before saving.
		 * @param array $context
		 */
		public function actionsPreferences($context) {
			if (
				!isset($context['settings']['emailbuilder']['navigation_group'])
				|| trim($context['settings']['emailbuilder']['navigation_group']) == ''
			) {
				$context['errors']['emailbuilder']['navigation_group'] = __('This is a required field.');
				$this->missing_navigation_group = true;
			}
		}
		
		/**
		 * View preferences.
		 * @param array $context
		 */
		public function viewPreferences($context) {
			$wrapper = $context['wrapper'];
			$errors = Symphony::Engine()->Page->_errors;
			
			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Email Builder')));
			
			$label = Widget::Label(
				__('Navigation Group')
				. ' <i>'
				. __('Created if does not exist')
				. '</i>'
			);
			$label->appendChild(Widget::Input(
				'settings[emailbuilder][navigation_group]',
				$this->getNavigationGroup()
			));
			
			if (isset($errors['emailbuilder']['navigation_group'])) {
				$label = Widget::wrapFormElementWithError($label, $errors['emailbuilder']['navigation_group']);
			}
			
			$fieldset->appendChild($label);
			
			$list = new XMLElement('ul');
			$list->setAttribute('class', 'tags singular');
			
			foreach ($this->getNavigationGroups() as $group) {
				$list->appendChild(new XMLElement('li', $group));
			}
			
			$fieldset->appendChild($list);
			$wrapper->appendChild($fieldset);
		}
	}
	
?>