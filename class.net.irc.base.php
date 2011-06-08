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

class netIrc_Base extends netSocket {
	// Clearbricks' netSocket
	public $netSocket = null;			# Instance of netSocket
	public $netSocketIterator = null;	# Instance of netSocketIterator

	// IRC
	protected $ircChannelPrefixes = null;	# Channel prefixes
	protected $ircChannels = array();		# Channels storage
	protected $ircHost = null;				# Server host
	protected $ircIdent = null;				# Ident used
	protected $ircNick = null;				# Nickname used
	protected $ircPort = null;				# Server port
	protected $ircRealname = null;			# Realname used
	protected $ircUsers = array();			# Users storage
	protected $ircMotd = null;				# Server MOTD
	protected $ircLoggedIn = false;			# Connected or not
	protected $ircBuffers = null;			# Send queues
	protected $ircLine = null;				# Last line read from the server
	protected $ircChannelModes = array();	# Channel modes
	protected $ircNickPrefixes = array();	# Nicknames prefixes
	protected $ircLastReceived = null;		# Last received time
	protected $ircLoginSent = false;		# Have we send the connection infos?
	protected $ircReconnect = true;			# Shoul the class automatically reconnect to IRC?

	// Internal
	protected $eventHandlers = array();		# Event handlers
	protected $debugEnabled = true;			# Debug to stdout or not
	protected $loopBreak = false;
	
	// Bucket pacing
	protected $bucketLastSent;
	protected $bucketMaxBytes = 512;
	protected $bucketMaxTime = 3;
	protected $bucketQueue;
	protected $bucketWaiting = 5;
	protected $bucketCapacity = 512;

	#####################################
	#		CONSTRUCTOR/DESTRUCTOR		#
	#####################################

	public function __construct($host,$port,$nick,$ident,$realname)
	{
		declare(ticks = 1);
		pcntl_signal(SIGTERM,array($this, '__sigHandler'));
		pcntl_signal(SIGINT,array($this, '__sigHandler'));
		stream_set_blocking(STDIN,0);

		$this->ircHost = $host;
		$this->ircPort = (int) $port;
		$this->ircNick = $nick;
		$this->ircIdent = $ident;
		$this->ircRealname = $realname;

		$this->ircBuffers = range(1,6);
		foreach ($this->ircBuffers as &$v) { $v = array(); }
		$this->eventHandlers = array();
	}

	public function __destruct()
	{
		// nothing for now
	}

	#################################
	#		HANDLERS FUNCTIONS		#
	#################################

	public function registerHandler($type,$name,$callback,$regex = null)
	{
		if (is_callable($callback))
		{
			if (!isset($this->eventHandlers[$type]))
			{
				$this->eventHandlers[$type] = array();
			}

			$this->eventHandlers[$type][$name] = array();
			$this->eventHandlers[$type][$name]['regex'] = $regex;
			$this->eventHandlers[$type][$name]['callback'] = $callback;

			$this->__debug('|| INTERNAL: Adding handler '.$name.' for '.$type);
			return true;
		} else
		{
			$this->__debug('|| INTERNAL: WARNING: invalid callback '.$name.' for '.$type);
			return false;
		}
	}

	public function unregisterHandler($type,$name)
	{
		if (isset($this->eventHandlers[$type]) && isset($this->eventHandlers[$type][$name]))
		{
			unset($this->eventHandlers[$type][$name]);
			$this->__debug('|| INTERNAL: Deleting handler '.$name.' for '.$type);
			return true;
		} else
		{
			return false;
		}
	}

	#####################
	#		GETTERS		#
	#####################

	public function getNick() { return $this->ircNick; }

	public function getMotd() { return $this->ircMotd; }

	public function getChannels() { return $this->ircChannels; }

	public function getUsers() { return $this->ircUsers; }

	public function getChannel($_channel,$_key = false)
	{
		foreach ($this->ircChannels as $key => $Channel) {
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
		foreach ($this->ircUsers as $key => $User) {
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
		foreach ($this->ircChannels as $Channel) {
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
		return $this->ircUsers[0];
	}

	#####################################
	#		CONNECTION MANAGEMENT		#
	#####################################

	public function connect() {
		$this->ircLoggedIn = false;
		$this->ircLoginSent = false;
		$this->ircChannels = array();
		$this->ircUsers = array();

		unset($this->netSocketIterator);
		if ($this->isOpen()) { $this->close(); }
		
		$counter = 0;
		$this->_host = $this->ircHost;
		$this->_port = abs((integer) $this->ircPort);
		$this->timeout(30);
		
		while (true)
		{
			try
			{
				$this->netSocketIterator = $this->open();
				if ($this->isOpen()) { break; }
			} catch (Exception $e)
			{
				$counter++;
				$this->__debug('|| INTERNAL: WARNING: Connection failed: '.$e->getMessage());
				if ($counter == 3)
				{
					$this->__debug('|| INTERNAL: WARNING: Giving up');
					return false;
				} else
				{
					$this->__debug('|| INTERNAL: WARNING: Trying again in 30s');
					sleep(30);
				}
			}
		}

		return true;
	}

	public function deconnect($msg = null) {
		$this->ircReconnect = false;
		$this->__flushBuffer();
		$this->sendQuit($msg);
	}

	#########################
	#		MAIN LOOP		#
	#########################

	public function listen()
	{
		$this->__debug('|| INTERNAL: Entering loop...');
		$_StdIn = STDIN;
		$StdIn = new netSocketIterator($_StdIn);
		while (true)
		{
			$this->__checkBuffer();
			
			$_r = $_streams = array('irc' => $this->_handle,'stdin' => STDIN);
			if (@stream_select($_r,$_w = null,$_e = null,$this->bucketWaiting))
			{
				foreach ($_r as $_v) {
					$_stream = array_search($_v,$_streams);
					if ($_stream == 'irc')
					{
						$this->netSocketIterator->next();
						$this->__rawReceive($this->netSocketIterator->current());
						$this->ircLastReceived = time();
					} elseif ($_stream == 'stdin')
					{
						$this->__readStdin($StdIn->current());
					}
				}

			}

			// Stayin' alive...
			if ($this->ircLoggedIn)
			{
				if ((time() - $this->ircLastReceived) >= 30)
				{
					$this->__debug('|| INTERNAL: Nothing happened since 30s, pinging myself...');
					$this->sendCtcpReq($this->ircNick,'PING '.time(),0);
				}

				if ((time() - $this->ircLastReceived) >= 35) {
					$this->__debug('|| INTERNAL: WARNING: Seems we\'re not connected...');
					if ($this->ircReconnect)
					{
						$this->__debug('|| INTERNAL: Trying to re-establish connection...');
						if ($this->connect())
						{
							$this->listen();
						}
					}
					$this->__debug('|| INTERNAL: We shloud not reconnect, ending...');
					$this->loopBreak = true;
				}
			}

			// Check if we should break the loop...
			if ($this->loopBreak)
			{
				$this->loopBreak = false;
				break;
			}
		}
		$this->__debug('|| INTERNAL: Ending loop...');
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
					$this->__debug('|| INTERNAL: WARNING: Unreconized CTCP?');
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
			$this->__debug('|| INTERNAL: FATAL: LINE NOT PARSED!');
			$this->__debug($in);
			$this->deconnect('ERROR');
			exit();
		}

		if ($res->command === 'NOTICE' && $res->target === 'AUTH')
		{
			$res->target = $this->ircNick;
			$res->command = 'NOTICEAUTH';
		}

		if (strpos($this->ircChannelPrefixes,substr($res->target,0,1)) !== false)
		{
			$res->target_ischannel = true;
		}
		return $res;
	}

	protected function __callHandler($type,$Line)
	{
		if (is_callable(array($this,'__handle'.$type)))
		{
			$this->__debug('|| INTERNAL: Calling internal handler for '.$type);
			call_user_func(array($this,'__handle'.$type),$Line);
		}

		if (isset($this->eventHandlers[$type]))
		{
			foreach ($this->eventHandlers[$type] as $k => &$v)
			{
				if (is_callable($v['callback']))
				{
					if (is_null($v['regex'])|| !isset($Line->message_stripped) || preg_match($v['regex'],$Line->message_stripped))
					{
						$this->__debug('|| INTERNAL: Calling external handler '.$k.' for '.$type);
						call_user_func($v['callback'],$this,$Line);
					}
				} else
				{
					$this->__debug('|| INTERNAL: WARNING: invalid callback '.$k.' for '.$type);
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
		//	$this->__debug('|| INTERNAL: Sending '.$this->strBytesCounter($Line).'bytes ('.strlen($Line).'chars)');
			$this->__debug('>> '.trim($Line));
			$this->write($Line);
		} else { array_push($this->ircBuffers[$priority],$Line); }
	}
	
	protected function __bucketCanSend($Line)
	{
		$time = time();
		$bytes = $this->strBytesCounter($Line);
		$recover = $this->bucketMaxBytes / $this->bucketMaxTime;
		
		if (!is_null($this->bucketLastSent)) { $this->bucketCapacity += floor(($time - $this->bucketLastSent) * $recover); }
		if ($this->bucketCapacity > $this->bucketMaxBytes)
		{
			$this->bucketCapacity = $this->bucketMaxBytes;
		}
		
		if ($bytes <= $this->bucketCapacity)
		{
			$this->bucketCapacity = $this->bucketCapacity - $bytes;
			$this->bucketLastSent = $time;
			$this->bucketWaiting = 0;
			$this->__debug('|| INTERNAL: BUCKET: Sending '.$bytes.'bytes, now have '.$this->bucketCapacity.'bytes available');
			return true;
		}
		
		$waiting = ceil($bytes / $recover);
		
		$this->bucketQueue = $Line;
		$this->bucketWaiting = $waiting;
		$this->__debug('|| INTERNAL: BUCKET: Trying to send '.$bytes.'bytes with only '.$this->bucketCapacity.' available, waiting '.$waiting.'s');
		return false;
	}

	protected function __checkBuffer()
	{
		if (!$this->ircLoggedIn) { return false; }
		if ($this->bucketQueue !== null)
		{
			$Line = $this->bucketQueue;
			$this->bucketQueue = null;
		}
		
		if (!isset($Line))
		{
			foreach ($this->ircBuffers as &$buffer)
			{
				$Line = array_shift($buffer);
				if ($Line !== null) { break; }
			}
		}
		
		if ($Line === null)
		{
			$this->bucketWaiting = 5;
			return false;
		}
		
		if ($this->__bucketCanSend($Line))
		{
			$this->__send($Line,0);
			return true;
		}

		return false;
	}

	public function __flushBuffer() { while ($this->__checkBuffer()) { continue; } }

	protected function __debug($x)
	{
		if (!$this->debugEnabled) { return false; }
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
		unset($this->ircChannels[$Channel_key]);
		$this->__deleteUsers();
	}

	public function __deleteUsers()
	{
		foreach ($this->ircUsers as $User)
		{
			if (count($User->channels) === 0)
			{
				if ($User->nick != $this->ircNick) { $this->__deleteUser($User->nick); }
			}
		}
	}

	public function __deleteUser($_user)
	{
		$User = $this->getUser($_user);
		if ($User->nick == $this->ircNick) { return; }
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
		unset($this->ircUsers[$User_key]);
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

	#####################################
	#		SYSTEM SIGNALS HANDLING		#
	#####################################

	public function __sigHandler($signal)
	{
		if ($signal === SIGINT) { $sig = 'SIGINT'; }
		if ($signal === SIGTERM) { $sig = 'SIGTERM'; }

		if (isset($sig))
		{
			$this->__debug('|| INTERNAL: FATAL: Received '.$sig);
			$this->deconnect('Received '.$sig);
		}
	}
}
?>
