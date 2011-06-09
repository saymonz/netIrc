<?php
/*
 *      This program is free software; you can redistribute it and/or modify
 *      it under the terms of the GNU General Public License as published by
 *      the Free Software Foundation; either version 2 of the License, or
 *      (at your option) any later version.
 *
 *      This program is distributed in the hope that it will be useful,
 *      but WITHOUT ANY WARRANTY; without even the implied warranty of
 *      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *      GNU General Public License for more details.
 *
 *      You should have received a copy of the GNU General Public License
 *      along with this program; if not, write to the Free Software
 *      Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 *      MA 02110-1301, USA.
 */

class netIrc_Base extends netSocket
{
	// Clearbricks' netSocket
	public $netSocketIterator = null;			# Instance of netSocketIterator

	// IRC
	protected $irc_channel_prefixes;			# Channel prefixes
	protected $irc_channels = array();			# Channels storage
	protected $irc_ident;						# Ident used
	protected $irc_nickname;					# Nickname used
	protected $irc_realname;					# Realname used
	protected $irc_users = array();				# Users storage
	protected $irc_motd;						# Server MOTD
	protected $irc_logged_in;					# Connected or not
	protected $irc_buffers;						# Send queues
	protected $irc_channel_modes = array();		# Channel modes
	protected $irc_nicknamePrefixes = array();	# Nicknames prefixes
	protected $irc_last_received_time;			# Last received time
	protected $irc_auto_reconnect = true;		# Should the class automatically reconnect to IRC?

	// Internal
	protected $irc_event_handlers = array();	# Event handlers
	protected $irc_debug_enabled = true;		# Debug to stdout or not
	protected $irc_loop_break = false;			# Shall we exit the loop ?

	protected $irc_last_sent_time;
	protected $irc_max_bytes = 512;
	protected $irc_max_time = 3;
	protected $irc_next_out;
	protected $irc_next_out_waiting = 5;
	protected $irc_bytes_capacity = 512;

	#####################################
	#		CONSTRUCTOR/DESTRUCTOR		#
	#####################################

	public function __construct($host,$port,$ssl,$nick,$ident,$realname)
	{
		$this->__debug('|| CORE: Constructing...');
		// Signals handling
		declare(ticks = 1);
		pcntl_signal(SIGTERM,array($this, '__sigHandler'));
		pcntl_signal(SIGINT,array($this, '__sigHandler'));

		$this->irc_nickname = $nick;
		$this->irc_ident = $ident;
		$this->irc_realname = $realname;

		// netSocket vars
		parent::__construct($host,$port,30);
		if ($ssl) { $this->_transport = 'ssl://'; }

		// Internals
		$this->irc_buffers = range(1,6);
		foreach ($this->irc_buffers as &$v) { $v = array(); }
		$this->irc_event_handlers = array();
	}

	public function __destruct()
	{
		$this->__debug('|| CORE: Destructing...');
		parent::__destruct();
	}

	#################################
	#		HANDLERS FUNCTIONS		#
	#################################

	public function registerHandler($type,$name,$callback,$regex = null)
	{
		if (is_callable($callback))
		{
			if (!isset($this->irc_event_handlers[$type]))
			{
				$this->irc_event_handlers[$type] = array();
			}

			$this->irc_event_handlers[$type][$name] = array();
			$this->irc_event_handlers[$type][$name]['regex'] = $regex;
			$this->irc_event_handlers[$type][$name]['callback'] = $callback;

			$this->__debug('|| CORE: Adding handler '.$name.' for '.$type);
			return true;
		} else
		{
			$this->__debug('|| CORE: WARNING: invalid callback '.$name.' for '.$type);
			return false;
		}
	}

	public function unregisterHandler($type,$name)
	{
		if (isset($this->irc_event_handlers[$type]) && isset($this->irc_event_handlers[$type][$name]))
		{
			unset($this->irc_event_handlers[$type][$name]);
			$this->__debug('|| CORE: Deleting handler '.$name.' for '.$type);
			return true;
		} else
		{
			return false;
		}
	}

	#####################
	#		GETTERS		#
	#####################

	public function getNick() { return $this->irc_nickname; }

	public function getMotd() { return $this->irc_motd; }

	public function getChannels() { return $this->irc_channels; }

	public function getUsers() { return $this->irc_users; }

	public function getChannel($_channel,$_key = false)
	{
		foreach ($this->irc_channels as $key => $Channel) {
			if ($Channel->name == $_channel)
			{
				if ($_key) { return $key; }
				return $Channel;
			}
		}
		return false;
	}

	public function getUser($_nick,$_key = false)
	{
		foreach ($this->irc_users as $key => $User) {
			if ($User->nick == $_nick)
			{
				if ($_key) { return $key; }
				return $User;
			}
		}
		return false;
	}

	public function getChannelUser($_channel,$_user,$_key = false)
	{
		foreach ($this->irc_channels as $Channel) {
			if ($Channel->name == $_channel)
			{
				foreach ($Channel->users as $key => $ChannelUser)
				{
					if ($ChannelUser->user->nick == $_user)
					{
						if ($_key) { return $key; }
						return $ChannelUser;
					}
				}
			}
		}
		return false;
	}

	public function getMyself()
	{
		return $this->irc_users[0];
	}

	#####################################
	#		CONNECTION MANAGEMENT		#
	#####################################

	public function close() {
		$this->irc_auto_reconnect = false;
	}

	public function deconnect($quit = null)
	{
		$this->sendQuit($quit);
		$this->close();
	}

	public function open() {
		$this->irc_logged_in = false;
		$this->irc_channels = array();
		$this->irc_users = array();

		unset($this->netSocketIterator);
		if ($this->isOpen()) { $this->close(); }

		$counter = 0;

		while (true)
		{
			try
			{
				$this->netSocketIterator = parent::open();
				if ($this->isOpen()) {
					$this->sendNick($this->irc_nickname,0);
					$this->sendUser($this->irc_ident,$this->irc_realname);
					break;
				}
			} catch (Exception $e)
			{
				$counter++;
				$this->__debug('|| CORE: WARNING: Connection failed: '.$e->getMessage());
				if ($counter == 3)
				{
					$this->__debug('|| CORE: WARNING: Giving up');
					return false;
				} else
				{
					$this->__debug('|| CORE: WARNING: Trying again in 30s');
					sleep(30);
				}
			}
		}

		return true;
	}

	#########################
	#		MAIN LOOP		#
	#########################

	public function listen()
	{
		$this->__debug('|| CORE: Entering loop...');
		$_StdIn = STDIN;
		$StdIn = new netSocketIterator($_StdIn);
		while (true)
		{
			$this->__debug('|| CORE: Looping!');
			$this->__checkBuffer();

			$_r = $_streams = array('irc' => $this->_handle,'stdin' => STDIN);
			if (@stream_select($_r,$_w = null,$_e = null,$this->irc_next_out_waiting))
			{
				foreach ($_r as $_v) {
					$_stream = array_search($_v,$_streams);
					if ($_stream == 'irc')
					{
						$this->netSocketIterator->next();
						$this->__rawReceive($this->netSocketIterator->current());
						$this->irc_last_received_time = time();
					} elseif ($_stream == 'stdin')
					{
						$this->__readStdin($StdIn->current());
					}
				}

			}

			// Stayin' alive...
			if ($this->irc_logged_in)
			{
				if ((time() - $this->irc_last_received_time) >= 30)
				{
					$this->__debug('|| CORE: Nothing happened since 30s, pinging myself...');
					$this->sendCtcpReq($this->irc_nickname,'PING '.time(),0);
				}

				if ((time() - $this->irc_last_received_time) >= 60) {
					$this->__debug('|| CORE: WARNING: Seems we\'re not connected...');
					if ($this->irc_auto_reconnect)
					{
						$this->__debug('|| CORE: Trying to re-establish connection...');
						if ($this->connect())
						{
							$this->listen();
						}
					}
					$this->__debug('|| CORE: We should not reconnect, ending...');
					$this->irc_loop_break = true;
				}
			}

			// Check if we should break the loop...
			if ($this->irc_loop_break)
			{
				$this->irc_loop_break = false;
				break;
			}
		}
		$this->__debug('|| CORE: Ending loop...');
	}

	#####################################
	#		INCOMING DATAS HANDLING		#
	#####################################

	protected function __readStdin($Line)
	{
		$Line = trim($Line);
		if ($Line === '') { return false; }

		if ($Line === '::DIE') { $this->deconnect('Received STDIN::DIE'); }
		else { $this->sendRaw($Line,0); }
	}

	protected function __rawReceive($Line)
	{
		$parsed = $this->__ircParser($Line);
		if (!$parsed) { return false; }
		$this->__debug('<< '.$parsed->raw);
		$this->__callHandler($parsed->command,$parsed);
		return true;
	}

	public function __ircParser($in)
	{
		$in = trim(text::toUTF8($in));
		if ($in == null) { return false; }

		$res = new netIrc_Line;
		$res->raw = $in;

		$match = array();
		if (preg_match('#^:(.+) :(.*)$#U',$in,$match))
		{
			$match[1] = explode(' ',$match[1]);

			$res->source = $this->isMask(array_shift($match[1]),true);


			$res->command = array_shift($match[1]);
			$res->target = array_shift($match[1]);

			$res->args = $match[1];


			$res->message = $match[2];
			$res->message_xt = explode(' ',$res->message);

			$res->message_stripped = $this->ircStripper($match[2]);
			$res->message_stripped_xt = explode(' ',$res->message_stripped);

			$match = array();
			if (preg_match('#^[\x01](.+)[\x01]$#',$res->message,$match))
			{
				$res->message = $match[1];
				$res->message_xt = explode(' ',$res->message);

				$res->message_stripped = $this->ircStripper($match[1]);
				$res->message_stripped_xt = explode(' ',$res->message_stripped);

				if ($res->command === 'PRIVMSG')
				{
					if ($res->message_xt[0] === 'ACTION')
					{
						$res->command = 'ACTION';
						$res->message = implode(' ',array_slice($res->message_xt,1));
						$res->message_xt = explode(' ',$res->message);
					} else
					{
						$res->command = 'CTCPREQ';
					}
				} elseif ($res->command === 'NOTICE')
				{
					$res->command = 'CTCPREP';
				} else
				{
					$this->__debug('|| CORE: WARNING: Unreconized CTCP?');
				}
			}
		} elseif (preg_match('#^:(.+)$#U',$in,$match))
		{
			$match[1] = explode(' ',$match[1]);

			$res->source = $this->isMask(array_shift($match[1]),true);


			$res->command = array_shift($match[1]);
			$res->target = array_shift($match[1]);

			$res->args = $match[1];
		} elseif (preg_match('#^(.+):(.*)$#U',$in,$match))
		{
			$match[1] = explode(' ',$match[1]);

			$res->command = array_shift($match[1]);
			$res->args = $match[1];
			$res->message = $match[2];
			$res->message_xt = explode(' ',$match[2]);
		} else
		{
			$this->__debug('|| CORE: FATAL: LINE NOT PARSED!');
			$this->__debug($in);
			$this->deconnect('ERROR');
			exit();
		}

		if ($res->command === 'NOTICE' && $res->target === 'AUTH')
		{
			$res->target = $this->irc_nickname;
			$res->command = 'NOTICEAUTH';
		}

		if (strpos($this->irc_channel_prefixes,substr($res->target,0,1)) !== false)
		{
			$res->target_ischannel = true;
		}
		return $res;
	}

	protected function __callHandler($type,$Line)
	{
		if (is_callable(array($this,'__handle'.$type)))
		{
			$this->__debug('|| CORE: Calling internal handler for '.$type);
			call_user_func(array($this,'__handle'.$type),$Line);
		}

		if (isset($this->irc_event_handlers[$type]))
		{
			foreach ($this->irc_event_handlers[$type] as $k => &$v)
			{
				if (is_callable($v['callback']))
				{
					if (is_null($v['regex'])|| !isset($Line->message_stripped) || preg_match($v['regex'],$Line->message_stripped))
					{
						$this->__debug('|| CORE: Calling external handler '.$k.' for '.$type);
						call_user_func($v['callback'],$this,$Line);
					}
				} else
				{
					$this->__debug('|| CORE: WARNING: invalid callback '.$k.' for '.$type);
				}
			}
		}
		return true;
	}

	#####################################
	#		OUTGOING DATAS HANDLING		#
	#####################################

	protected function __send($Line,$priority = 3)
	{
		$Line = trim(text::toUTF8($Line));
		if ($Line == null) { return; }
		$Line .= "\n";

		if (!is_numeric($priority) || $priority < 0 || $priority > 5)
		{
			$priority = 3;
		}

		if ($priority == 0)
		{
		//	$this->__debug('|| CORE: Sending '.$this->strBytesCounter($Line).'bytes ('.strlen($Line).'chars)');
			$this->__debug('>> '.trim($Line));
			$this->write($Line);
		} else { array_push($this->irc_buffers[$priority],$Line); }
	}

	protected function __canSend($Line)
	{
		$time = time();
		$bytes = $this->strBytesCounter($Line);
		$recover = $this->irc_max_bytes / $this->irc_max_time;

		if (!is_null($this->irc_last_sent_time)) { $this->irc_bytes_capacity += floor(($time - $this->irc_last_sent_time) * $recover); }
		if ($this->irc_bytes_capacity > $this->irc_max_bytes)
		{
			$this->irc_bytes_capacity = $this->irc_max_bytes;
		}

		if ($bytes <= $this->irc_bytes_capacity)
		{
			$this->irc_bytes_capacity = $this->irc_bytes_capacity - $bytes;
			$this->irc_last_sent_time = $time;
			$this->irc_next_out_waiting = 0;
			$this->__debug('|| CORE: BUCKET: Sending '.$bytes.'bytes, now have '.$this->irc_bytes_capacity.'bytes available');
			return true;
		}

		$waiting = ceil($bytes / $recover);

		$this->irc_next_out = $Line;
		$this->irc_next_out_waiting = $waiting;
		$this->__debug('|| CORE: BUCKET: Trying to send '.$bytes.'bytes with only '.$this->irc_bytes_capacity.' available, waiting '.$waiting.'s');
		return false;
	}

	protected function __checkBuffer()
	{
		if (!$this->irc_logged_in) { return false; }
		if ($this->irc_next_out !== null)
		{
			$Line = $this->irc_next_out;
			$this->irc_next_out = null;
		} else
		{
			foreach ($this->irc_buffers as &$buffer)
			{
				$Line = array_shift($buffer);
				if ($Line !== null) { break; }
			}
		}

		if ($Line !== null)
		{
			if ($this->__canSend($Line))
			{
				$this->__send($Line,0);
				return true;
			} else
			{
				return false;
			}
		} else
		{
			$this->irc_next_out_waiting = 30;
			return false;
		}
	}

	protected function __debug($x)
	{
		if (!$this->irc_debug_enabled) { return false; }
		if ($this->isOpen())
		{
			$key = $this->netSocketIterator->key();
		} else {
			$key = 'T'.time();
		}

		echo '#'.$key."\t".$x."\n";
		return true;
	}

	#################################
	#		CORE DATAS HANDLING		#
	#################################

	public function __deleteChannel($_channel)
	{
		$Channel = $this->getChannel($_channel);
		$Channel_key = $this->getChannel($_channel,true);
		if ($Channel === false) { return false; }

		foreach ($Channel->users as $ChannelUser)
		{
			foreach ($ChannelUser->user->channels as $_k => $_Channel)
			{
				if ($_Channel->name == $Channel->name)
				{
					unset($ChannelUser->user->channels[$_k]);
				}
			}
		}
		unset($this->irc_channels[$Channel_key]);
		$this->__deleteUsers();
	}

	public function __deleteChannelUser($_channel,$_user)
	{
		$Channel = $this->getChannel($_channel);
		$User = $this->getUser($_user);
		if ($Channel === false) { return false; }
		if ($User === false) { return false; }

		foreach ($Channel->users as $key => $ChannelUser)
		{
			if ($ChannelUser->user->nick == $_user)
			{

				unset($Channel->users[$key]);
			}
		}

		foreach ($User->channels as $key => $Channel)
		{
			if ($Channel->name == $_channel)
			{
				unset($User->channels[$key]);
			}
		}

		if (count($User->channels) === 0)
		{
			$this->__deleteUser($User->nick);
		}
	}

	public function __deleteUser($_user)
	{
		$User = $this->getUser($_user);
		if ($User->nick == $this->irc_nickname) { return; }
		$User_key = $this->getUser($_user,true);
		if ($User === false) { return false; }

		foreach ($User->channels as $Channel)
		{
			foreach ($Channel->users as $_k => $_ChannelUser)
			{
				if ($_ChannelUser->user->nick == $User->nick)
				{
					unset($Channel->users[$_k]);
				}
			}
		}
		unset($this->irc_users[$User_key]);
	}

	public function __deleteUsers()
	{
		foreach ($this->irc_users as $User)
		{
			if (count($User->channels) === 0)
			{
				if ($User->nick != $this->irc_nickname) { $this->__deleteUser($User->nick); }
			}
		}
	}



	#####################################
	#		SYSTEM SIGNALS HANDLING		#
	#####################################

	public function __sigHandler($signal)
	{
		if ($signal === SIGINT) { $sig = 'SIGINT'; }
		if ($signal === SIGTERM) { $sig = 'SIGTERM'; }

		if (isset($sig))
		{
			$this->__debug('|| CORE: FATAL: Received '.$sig);
			$this->deconnect('Received '.$sig);
		}
	}
}
?>
