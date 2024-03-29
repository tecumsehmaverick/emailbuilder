<?php
	
	require_once(TOOLKIT . '/class.administrationpage.php');
	require_once(TOOLKIT . '/class.datasourcemanager.php');
	
	class contentExtensionEmailTemplateFilterTemplates extends AdministrationPage {
		protected $_action = '';
		protected $_conditions = array();
		protected $_driver = null;
		protected $_editing = false;
		protected $_errors = array();
		protected $_fields = array();
		protected $_pages = array();
		protected $_prepared = false;
		protected $_status = '';
		protected $_templates = array();
		protected $_uri = null;
		protected $_valid = true;
		
		public function __construct($parent) {
			parent::__construct($parent);
			
			$this->_uri = URL . '/symphony/extension/emailtemplatefilter';
			$this->_driver = Symphony::ExtensionManager()->create('emailtemplatefilter');
		}
		
		public function build($context) {
			if (isset($context[0]) && ($context[0] == 'edit' || $context[0] == 'new')) {
				if ($this->_editing = $context[0] == 'edit') {
					$this->_fields = $this->_driver->getTemplate((integer)$context[1]);
					$this->_conditions = $this->_driver->getConditions((integer)$context[1]);
				}
				
				$this->_fields = (isset($_POST['fields']) ? $_POST['fields'] : $this->_fields);
				$this->_conditions = (isset($_POST['conditions']) ? $_POST['conditions'] : $this->_conditions);
				$this->_status = $context[2];
				$this->_pages = $this->_driver->getPages();
			}
			
			else {
				$this->_templates = $this->_driver->getTemplates();
			}
			
			parent::build($context);
		}
		
		public function __actionNew() {
			$this->__actionEdit();
		}
		
		public function __actionEdit() {
			if (isset($_POST['action']) && array_key_exists('delete', $_POST['action'])) {
				$this->__actionEditDelete();
			}
			
			else {
				$this->__actionEditNormal();
			}
		}
		
		public function __actionEditDelete() {
			Symphony::Database()->delete('tbl_etf_templates', " `id` = '{$this->_fields['id']}'");
			Symphony::Database()->delete('tbl_etf_conditions', " `template_id` = '{$this->_fields['id']}'");
			
			redirect("{$this->_uri}/templates/");
		}
		
		public function __actionEditNormal() {
		// Validate: ----------------------------------------------------------
			
			if (empty($this->_fields['name'])) {
				$this->_errors['name'] = __('Name must not be empty.');
			}
			
			foreach ($this->_conditions as $sortorder => $condition) {
				if (empty($condition['subject'])) {
					$this->_errors["{$sortorder}:subject"] = __('Subject must not be empty.');
				}
				
				if (empty($condition['sender'])) {
					$this->_errors["{$sortorder}:sender"] = __('Sender Name must not be empty.');
				}
				
				if (empty($condition['senders'])) {
					$this->_errors["{$sortorder}:senders"] = __('Senders must not be empty.');
				}
				
				if (empty($condition['recipients'])) {
					$this->_errors["{$sortorder}:recipients"] = __('Recipients must not be empty.');
				}
				
				if (empty($condition['page'])) {
					$this->_errors["{$sortorder}:page"] = __('Page must not be empty.');
				}
			}
			
			if (!empty($this->_errors)) {
				$this->_valid = false;
				return;
			}
			
		// Save: --------------------------------------------------------------
			
			$this->_fields['conditions'] = (integer)count($this->_conditions);
			$this->_fields['datasources'] = (
				isset($this->_fields['datasources']) && is_array($this->_fields['datasources'])
					? implode(',', $this->_fields['datasources'])
					: null
			);
			
			Symphony::Database()->insert($this->_fields, 'tbl_etf_templates', true);
			
			if (!$this->_editing) {
				$redirect_mode = 'created';
				$template_id = Symphony::Database()->fetchVar('id', 0, "
					SELECT
						e.id
					FROM
						`tbl_etf_templates` AS e
					ORDER BY
						e.id DESC
					LIMIT 1
				");
			}
			
			else {
				$redirect_mode = 'saved';
				$template_id = $this->_fields['id'];
			}
			
			// Remove all existing conditions before inserting the remaining ones
			Symphony::Database()->delete('tbl_etf_conditions', " `template_id` = '{$this->_fields['id']}'");

			foreach ($this->_conditions as $condition) {
				$condition['template_id'] = $template_id;
				
				Symphony::Database()->insert($condition, 'tbl_etf_conditions', true);
			}
			
			redirect("{$this->_uri}/templates/edit/{$template_id}/{$redirect_mode}/");
		}
		
		public function __viewNew() {
			$this->__viewEdit();
		}
		
		public function __viewEdit() {
			$this->addStylesheetToHead(URL . '/extensions/emailtemplatefilter/assets/templates.css', 'screen', 1000);
			$this->addScriptToHead(URL . '/extensions/emailtemplatefilter/assets/templates.js', 1000);
			
		// Status: ---------------------------------------------------------------
			
			if (!$this->_valid) $this->pageAlert(
				__('An error occurred while processing this form. <a href="#error">See below for details.</a>'),
				Alert::ERROR
			);
			
			// Status message:
			if ($this->_status) {
				$action = null;
				
				switch($this->_status) {
					case 'saved': $action = '%1$s updated at %2$s. <a href="%3$s">Create another?</a> <a href="%4$s">View all %5$s</a>'; break;
					case 'created': $action = '%1$s created at %2$s. <a href="%3$s">Create another?</a> <a href="%4$s">View all %5$s</a>'; break;
				}
				
				if ($action) $this->pageAlert(
					__(
						$action, array(
							__('Template'), 
							DateTimeObj::get(__SYM_TIME_FORMAT__), 
							URL . '/symphony/extension/emailtemplatefilter/templates/new/', 
							URL . '/symphony/extension/emailtemplatefilter/templates/',
							__('Templates')
						)
					),
					Alert::SUCCESS
				);
			}
			
			// Edit:
			if ($this->_action == 'edit') {
				if ($this->_template > 0) {
					$row = Symphony::Database()->fetchRow(0, "
						SELECT
							e.*
						FROM
							`tbl_etf_templates` AS e
						WHERE
							e.id = {$this->_template}
					");
					
					if (!empty($row)) {
						$this->_fields = $row;
					}
					
					else {
						$this->_editing = false;
					}
				}
			}
			
		// Header -----------------------------------------------------------------
			
			$this->setPageType('form');
			$this->setTitle(__('Symphony &ndash; Email Templates') . (
				$this->_editing ? ' &ndash; ' . $this->_fields['name'] : null
			));
			$this->appendSubheading(
				$this->_editing ? $this->_fields['name'] : __('Untitled')
			);
			
		// Essentials -------------------------------------------------------------
			
			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Essentials')));
			
			if (!empty($this->_fields['id'])) {
				$fieldset->appendChild(Widget::Input("fields[id]", $this->_fields['id'], 'hidden'));
			}
			
			// Name:
			$label = Widget::Label(__('Name'));
			$label->appendChild(Widget::Input(
				'fields[name]',
				General::sanitize($this->_fields['name'])
			));
			
			if (isset($this->_errors['name'])) {
				$label = Widget::wrapFormElementWithError($label, $this->_errors['name']);
			}
			
			$fieldset->appendChild($label);
			$this->Form->appendChild($fieldset);
			
		// Content ----------------------------------------------------------------
			
			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Content')));
			
			$help = new XMLElement('p');
			$help->setAttribute('class', 'help');
			$help->setValue(__(
				'To access the XML of your template page, use XPath expressions:<br /><code>%s</code>.',
				array('{datasource/entry/field-one}')
			));
			
			$fieldset->appendChild($help);
			
			// Subject:
			$label = Widget::Label(__('Subject'));
			$label->appendChild(Widget::Input(
				"fields[subject]",
				General::sanitize($fields['subject'])
			));
			
			if (isset($this->_errors['subject'])) {
				$label = Widget::wrapFormElementWithError($label, $this->_errors['subject']);
			}
			
			$fieldset->appendChild($label);
			
			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');
			
			// Sender Name
			$label = Widget::Label(__('Sender Name'));
			$label->appendChild(Widget::Input(
				"fields[sender]",
				General::sanitize($fields['sender'])
			));
			
			if (isset($this->_errors['sender'])) {
				$label = Widget::wrapFormElementWithError($label, $this->_errors['sender']);
			}
			
			$group->appendChild($label);
			
			// Senders
			$label = Widget::Label(__('Sender Address'));
			$label->appendChild(Widget::Input(
				"fields[senders]",
				General::sanitize($fields['senders'])
			));
			
			if (isset($this->_errors['senders'])) {
				$label = Widget::wrapFormElementWithError($label, $this->_errors['senders']);
			}
			
			$group->appendChild($label);
			$fieldset->appendChild($group);
			
			// Recipients
			$label = Widget::Label(__('Recipient Addresses'));
			$label->appendChild(Widget::Input(
				"fields[recipients]",
				General::sanitize($fields['recipients'])
			));
			
			if (isset($this->_errors['recipients'])) {
				$label = Widget::wrapFormElementWithError($label, $this->_errors['recipients']);
			}
			
			$fieldset->appendChild($label);
			$this->Form->appendChild($fieldset);
			
		// Template ---------------------------------------------------------------
			
			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Template')));
			
			$help = new XMLElement('p');
			$help->setAttribute('class', 'help');
			$help->setValue(__('The <code>%s</code> parameter can be used by any datasources on your template page.', array('$etf-entry-id')));
			
			$fieldset->appendChild($help);
			
			// Page:
			$div = new XMLElement('div');
			$div->setAttribute('class', 'group');
			
			$label = Widget::Label(__('Page'));
			$options = array(
				array(null, false, __('Choose one...'))
			);
			
			foreach ($this->_pages as $page) {
				$options[] = array(
					$page->id, ($page->id == $condition['page']), $page->path
				);
			}
			
			$label->appendChild(Widget::Select(
				"fields[page]", $options
			));
			
			if (isset($this->_errors['page'])) {
				$label = Widget::wrapFormElementWithError($label, $this->_errors['page']);
			}
			
			$div->appendChild($label);
			
			// Params:
			$label = Widget::Label(__('URL Parameters'));
			$label->appendChild(Widget::Input(
				"fields[params]",
				General::sanitize($fields['params'])
			));
			
			if (isset($this->_errors['params'])) {
				$label = Widget::wrapFormElementWithError($label, $this->_errors['params']);
			}
			
			$div->appendChild($label);
			$fieldset->appendChild($div);
			$this->Form->appendChild($fieldset);
			
		// Conditions -------------------------------------------------------------
			
			$fieldset = new XMLElement('fieldset');
			$fieldset->setAttribute('class', 'settings');
			$fieldset->appendChild(new XMLElement('legend', __('Overrides')));
			
			$help = new XMLElement('p');
			$help->setAttribute('class', 'help');
			$help->setValue(__('An override changes the above content and template when its expression matches an XML element or is <code>true()</code>.'));
			
			$fieldset->appendChild($help);
			
			$ol = new XMLElement('ol');
			$ol->setAttribute('id', 'email-conditions-duplicator');
			
			// Add existing conditions:
			foreach ($this->_conditions as $sortorder => $condition) {
				$wrapper = new XMLElement('li');
				
				$this->displayCondition($wrapper, $sortorder, $condition);
				
				$ol->appendChild($wrapper);
			}
			
			// Add condition template:
			$wrapper = new XMLElement('li');
			$wrapper->setAttribute('class', 'template');
			
			$this->displayCondition($wrapper, '-1', array(
				'type'		=> __('Override')
			));
			
			$ol->appendChild($wrapper);
			
			$fieldset->appendChild($ol);
			$this->Form->appendChild($fieldset);
			
		// Footer: ------------------------------------------------------------
			
			$div = new XMLElement('div');
			$div->setAttribute('class', 'actions');
			$div->appendChild(
				Widget::Input('action[save]',
					($this->_editing ? __('Save Changes') : __('Create Template')),
					'submit', array(
						'accesskey'		=> 's'
					)
				)
			);
			
			if ($this->_editing) {
				$button = new XMLElement('button', 'Delete');
				$button->setAttributeArray(array(
					'name'		=> 'action[delete]',
					'class'		=> 'confirm delete',
					'title'		=> __('Delete this template')
				));
				$div->appendChild($button);
			}
			
			$this->Form->appendChild($div);
		}
		
		protected function displayCondition(&$wrapper, $sortorder, $condition) {
			$wrapper->appendChild(new XMLElement('h4', ucwords($condition['type'])));
			$wrapper->appendChild(Widget::Input("conditions[{$sortorder}][type]", $condition['type'], 'hidden'));
			
			if (!empty($condition['id'])) {
				$wrapper->appendChild(Widget::Input("conditions[{$sortorder}][id]", $condition['id'], 'hidden'));
			}
			
		// Expression ---------------------------------------------------------
			
			$label = Widget::Label(__('Expression'));
			$label->appendChild(Widget::Input(
				"conditions[{$sortorder}][expression]",
				General::sanitize($condition['expression'])
			));
			
			if (isset($this->_errors["{$sortorder}:expression"])) {
				$label = Widget::wrapFormElementWithError($label, $this->_errors["{$sortorder}:expression"]);
			}
			
			$wrapper->appendChild($label);
			
		// Subject ------------------------------------------------------------
			
			$fieldset = new XMLElement('fieldset');
			$fieldset->appendChild(new XMLElement('legend', __('Content')));
			
			$label = Widget::Label(__('Subject'));
			$label->appendChild(Widget::Input(
				"conditions[{$sortorder}][subject]",
				General::sanitize($condition['subject'])
			));
			
			if (isset($this->_errors["{$sortorder}:subject"])) {
				$label = Widget::wrapFormElementWithError($label, $this->_errors["{$sortorder}:subject"]);
			}
			
			$fieldset->appendChild($label);
			
		// Sender Name --------------------------------------------------------
			
			$group = new XMLElement('div');
			$group->setAttribute('class', 'group');
			
			$label = Widget::Label(__('Sender Name'));
			$label->appendChild(Widget::Input(
				"conditions[{$sortorder}][sender]",
				General::sanitize($condition['sender'])
			));
			
			if (isset($this->_errors["{$sortorder}:sender"])) {
				$label = Widget::wrapFormElementWithError($label, $this->_errors["{$sortorder}:sender"]);
			}
			
			$group->appendChild($label);
			
		// Senders ------------------------------------------------------------
			
			$label = Widget::Label(__('Sender Address'));
			$label->appendChild(Widget::Input(
				"conditions[{$sortorder}][senders]",
				General::sanitize($condition['senders'])
			));
			
			if (isset($this->_errors["{$sortorder}:senders"])) {
				$label = Widget::wrapFormElementWithError($label, $this->_errors["{$sortorder}:senders"]);
			}
			
			$group->appendChild($label);
			$fieldset->appendChild($group);
			
		// Recipients ---------------------------------------------------------
			
			$label = Widget::Label(__('Recipient Addresses'));
			$label->appendChild(Widget::Input(
				"conditions[{$sortorder}][recipients]",
				General::sanitize($condition['recipients'])
			));
			
			if (isset($this->_errors["{$sortorder}:recipients"])) {
				$label = Widget::wrapFormElementWithError($label, $this->_errors["{$sortorder}:recipients"]);
			}
			
			$fieldset->appendChild($label);
			$wrapper->appendChild($fieldset);
			
		// Page ---------------------------------------------------------------
			
			$fieldset = new XMLElement('fieldset');
			$fieldset->appendChild(new XMLElement('legend', __('Template')));
			
			$div = new XMLElement('div');
			$div->setAttribute('class', 'group');
			
			$label = Widget::Label(__('Page'));
			$options = array(
				array(null, false, __('Choose one...'))
			);
			
			foreach ($this->_pages as $page) {
				$options[] = array(
					$page->id, ($page->id == $condition['page']), $page->path
				);
			}
			
			$label->appendChild(Widget::Select(
				"conditions[{$sortorder}][page]", $options
			));
			
			if (isset($this->_errors["{$sortorder}:page"])) {
				$label = Widget::wrapFormElementWithError($label, $this->_errors["{$sortorder}:page"]);
			}
			
			$div->appendChild($label);
			
		// Params -------------------------------------------------------------
			
			$label = Widget::Label(__('URL Parameters'));
			$label->appendChild(Widget::Input(
				"conditions[{$sortorder}][params]",
				General::sanitize($condition['params'])
			));
			
			if (isset($this->_errors["{$sortorder}:params"])) {
				$label = Widget::wrapFormElementWithError($label, $this->_errors["{$sortorder}:params"]);
			}
			
			$div->appendChild($label);
			$fieldset->appendChild($div);
			$wrapper->appendChild($fieldset);
		}
		
	/*-------------------------------------------------------------------------
		Index
	-------------------------------------------------------------------------*/
		
		public function __actionIndex() {
			$checked = (
				(isset($_POST['items']) && is_array($_POST['items']))
					? array_keys($_POST['items'])
					: null
			);
			
			if (is_array($checked) and !empty($checked)) {
				switch ($_POST['with-selected']) {
					case 'delete':
						foreach ($checked as $template_id) {
							Symphony::Database()->query("
								DELETE FROM
									`tbl_etf_templates`
								WHERE
									`id` = {$template_id}
							");
							
							Symphony::Database()->query("
								DELETE FROM
									`tbl_etf_conditions`
								WHERE
									`template_id` = {$template_id}
							");
						}
						
						redirect("{$this->_uri}/templates/");
						break;
				}
			}
		}
		
		public function __viewIndex() {
			$this->setPageType('table');
			$this->setTitle(__('Symphony &ndash; Email Templates'));
			
			$this->appendSubheading('Templates', Widget::Anchor(
				__('Create New'), "{$this->_uri}/templates/new/",
				__('Create a new email template'), 'create button'
			));
			
			$tableHead = array(
				array(__('Template Name'), 'col'),
				array(__('Conditions'), 'col')
			);	
			
			$tableBody = array();
			
			if (!is_array($this->_templates) or empty($this->_templates)) {
				$tableBody = array(
					Widget::TableRow(array(Widget::TableData(__('None Found.'), 'inactive', null, count($tableHead))))
				);
			}
			
			else {
				foreach ($this->_templates as $template) {
					$template = (object)$template;
					
					$col_name = Widget::TableData(
						Widget::Anchor(
							$template->name,
							"{$this->_uri}/templates/edit/{$template->id}/"
						)
					);
					$col_name->appendChild(Widget::Input("items[{$template->id}]", null, 'checkbox'));
					
					if (!empty($template->conditions)) {
						$col_conditions = Widget::TableData($template->conditions);
					}
					
					else {
						$col_conditions = Widget::TableData('None', 'inactive');
					}
					
					$tableBody[] = Widget::TableRow(array($col_name, $col_conditions), null);
				}
			}
			
			$table = Widget::Table(
				Widget::TableHead($tableHead), null, 
				Widget::TableBody($tableBody)
			);
			$table->setAttribute('class', 'selectable');
			
			$this->Form->appendChild($table);
			
			$actions = new XMLElement('div');
			$actions->setAttribute('class', 'actions');
			
			$options = array(
				array(null, false, __('With Selected...')),
				array('delete', false, 'Delete')									
			);

			$actions->appendChild(Widget::Select('with-selected', $options));
			$actions->appendChild(Widget::Input('action[apply]', __('Apply'), 'submit'));
			
			$this->Form->appendChild($actions);		
		}
	}
	
?>