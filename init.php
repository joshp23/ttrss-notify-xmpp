<?php
/*
 * Notifications via XMPP v1.0.0
 * Tiny Tiny RSS Plugin
 * 
 * Copyright (C) 2016 fulmeek
 * 
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 * 
 */

class notify_xmpp extends Plugin {
	private $host;

	function about() {
		return array(1.0,
			"Notifications via XMPP",
			"fulmeek");
	}

	function init($host) {
		$this->host = $host;

		$host->add_hook($host::HOOK_PREFS_TAB, $this);
		$host->add_hook($host::HOOK_ARTICLE_FILTER_ACTION, $this);

		$host->add_filter_action($this, "xmpp_send", "Send XMPP message");
	}

	function save() {
		$test_xmpp = true;
		
		$cfg = array(
			'xmpp_host'		=> $_POST['xmpp_host'],
			'xmpp_port'		=> $_POST['xmpp_port'],
			'xmpp_user'		=> $_POST['xmpp_user'],
			'xmpp_pass'		=> $_POST['xmpp_pass'],
			'xmpp_master'	=> $_POST['xmpp_master']
		);
		foreach ($cfg as $k => $v) {
			$this->host->set($this, $k, db_escape_string($v));
			if (empty($v)) $test_xmpp = false;
		}
		
		if ($test_xmpp) {
			$this->_send($cfg, 'Congrats, your settings work. Now you are ready to receive notifications.');
		}
		
		echo __("Data saved.");
	}

	function hook_prefs_tab($args) {
		if ($args != "prefPrefs") return;

		print '<div dojoType="dijit.layout.AccordionPane" title="'.__('XMPP Notification Settings').'">';

		$cfg = $this->_config(false);
		if ($cfg['xmpp_port'] <= 0) $cfg['xmpp_port'] = 5222;
		
		print '<p>After setting up your account information, you may invoke this plugin in your filter rules in order to receive notifications.</p>';
		
		print '<form dojoType="dijit.form.Form">';

		print "<script type=\"dojo/method\" event=\"onSubmit\" args=\"evt\">
			evt.preventDefault();
			if (this.validate()) {
				console.log(dojo.objectToQuery(this.getValues()));
				new Ajax.Request('backend.php', {
					parameters: dojo.objectToQuery(this.getValues()),
					onComplete: function(transport) {
						notify_info(transport.responseText);
					}
				});
				//this.reset();
			}
			</script>";
			
			print_warning('This plugin requires a <strong>separate XMPP account</strong> for sending notifications. Using the same account for sending and receiving might cause conflicts.');

			print '<input dojoType="dijit.form.TextBox" style="display : none" name="op" value="pluginhandler">';
			print '<input dojoType="dijit.form.TextBox" style="display : none" name="method" value="save">';
			print '<input dojoType="dijit.form.TextBox" style="display : none" name="plugin" value="notify_xmpp">';

		print '<table class="prefPrefsList">';
			print '<colgroup>';
				print '<col style="width: 40%;"/>';
				print '<col />';
			print '</colgroup>';

			print '<tr><td>'.__('XMPP Host').'</td>';
			print '<td class="prefValue"><input dojoType="dijit.form.ValidationTextBox" required="1" name="xmpp_host" type="text" value="'.$cfg['xmpp_host'].'" placeholder="example.org"></td></tr>';
			print '<tr><td>'.__('XMPP Port').'</td>';
			print '<td class="prefValue"><input dojoType="dijit.form.ValidationTextBox" required="1" name="xmpp_port" type="text" value="'.$cfg['xmpp_port'].'" placeholder="5222"></td></tr>';
			print '<tr><td>'.__('XMPP Username').'</td>';
			print '<td class="prefValue"><input dojoType="dijit.form.ValidationTextBox" required="1" name="xmpp_user" type="text" value="'.$cfg['xmpp_user'].'" placeholder="username"></td></tr>';
			print '<tr><td>'.__('XMPP Password').'</td>';
			print '<td class="prefValue"><input dojoType="dijit.form.ValidationTextBox" required="1" name="xmpp_pass" type="password" value="'.$cfg['xmpp_pass'].'" placeholder="password"></td></tr>';
			print '<tr><td>'.__('Sending to').'</td>';
			print '<td class="prefValue"><input dojoType="dijit.form.ValidationTextBox" required="1" name="xmpp_master" type="text" value="'.$cfg['xmpp_master'].'" placeholder="you@example.org"></td></tr>';
			
			print '</table>';

			print '<p><button dojoType="dijit.form.Button" type="submit">'.__('Save settings').'</button>';

			print '</form>';

		print '</div>';
	}
	
	function hook_article_filter_action($article, $action) {
		$owner_uid = (isset($article["owner_uid"])) ? $article["owner_uid"] : '';
		
		if ($action == 'xmpp_send') {
			$sent = false;
			$cfg = $this->_config();
			$plugin_data = (isset($article["plugin_data"])) ? explode(':', $article["plugin_data"]) : array();
			
			foreach ($plugin_data as $item) {
				if (strpos($item, 'notify_xmpp,'.$owner_uid) !== false) {
					$sent = true;
					break;
				}
			}
			if (!$sent && is_array($cfg)) {
				$msg = array();
				if (!empty($article["title"])) $msg[] = trim(html_entity_decode(strip_tags($article["title"])));
				if (!empty($article["link"])) $msg[] = $article["link"];
				if (!empty($article["content"])) {
					$text = html_entity_decode(strip_tags($article["content"]));
					if (strlen($text) > 512) $text = substr($text, 0, 512).'...';
					$msg[] = trim($text);
				}
				
				$this->_send($this->_config(), trim(implode("\n\n", $msg)));
				
				$plugin_data[] = 'notify_xmpp,'.$owner_uid;
				$article["plugin_data"] = implode(':', $plugin_data);
			}
		}
		
		return $article;
	}

	function api_version() {
		return 2;
	}
	
	private function _send($cfg, $msg) {
		if (!is_array($cfg) || empty($msg)) return;
		
		require_once dirname(__FILE__).'/xmpphp/XMPPHP/XMPP.php';
		try {
			$conn = new XMPPHP_XMPP($cfg['xmpp_host'], $cfg['xmpp_port'], $cfg['xmpp_user'], $cfg['xmpp_pass'], 'tt-rss/notify_xmpp');
			$conn->connect();
			$conn->processUntil('session_start');
			$conn->presence();
			$conn->message($cfg['xmpp_master'], $msg);
			$conn->disconnect();
		} catch(XMPPHP_Exception $e) {
			_debug('failed to send XMPP message');
		}
	}
	
	private function _config($check = true) {
		$cfg = array(
			'xmpp_host'		=> $this->host->get($this, 'xmpp_host'),
			'xmpp_port'		=> $this->host->get($this, 'xmpp_port'),
			'xmpp_user'		=> $this->host->get($this, 'xmpp_user'),
			'xmpp_pass'		=> $this->host->get($this, 'xmpp_pass'),
			'xmpp_master'	=> $this->host->get($this, 'xmpp_master')
		);
		if ($check) foreach ($cfg as $k => $v) {
			if (empty($v)) return NULL;
		}
		return $cfg;
	}
}
