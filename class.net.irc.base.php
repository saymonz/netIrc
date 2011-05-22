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

class netIrc_Base {
	// Clearbricks' netSocket
	protected $netSocket = null;			# Instance of netSocket
	protected $netSocketIterator = null;	# Instance of netSocketIterator

	// IRC
	protected $ircHost = null;				# Server host
	protected $ircPort = null;				# Server port
	protected $ircNick = null;				# Nickname used
	protected $ircIdent = null;				# Ident used
	protected $ircRealname = null;			# Realname used
	protected $ircChannels = array();		# Channels storage
	protected $ircChannelPrefixes = null;	# Channel prefixes
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
	protected $loopBreak = false;

	// Internal
	protected $eventHandlers = array();		# Event handlers
	protected $debugEnabled = true;			# Debug to stdout or not

	// Ticker
	protected $tickerInterval = 0;			# Actual ticker interval
	protected $tickerMax = 500000;			# Max ticker interval
	protected $tickerMin = 10000;			# Min ticker interval
	protected $tickerInc = 10000;			# Ticker incrementation

	#####################################
	#		CONSTRUCTOR/DESTRUCTOR		#
	#####################################

	public function __construct($host,$port,$nick,$ident,$realname)
	{
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
		unset($this->netSocket);

		$this->netSocket = new netSocket($this->ircHost,$this->ircPort);
		$this->netSocketIterator = $this->netSocket->open();
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
		$tickerSleep = $tickerMin = 100000;
		$tickerMax = 500000;
		$tickerInc = 50000;
		$pingerSent = false;
		$this->ircLastReceived = time();

		foreach ($this->netSocketIterator as $v)
		{
			if ($this->__rawReceive($v) || $this->__checkBuffer())
			{
				$tickerSleep = $tickerSleep / 2;
				$this->ircLastReceived = time();
				$pingerSent = false;
			} else {
				$tickerSleep += $tickerInc;
				if ($this->ircLoggedIn)
				{
					if ((time() - $this->ircLastReceived) >= 30 && !$pingerSent)
					{
						$this->__debug('|| INTERNAL: Nothing happened since 30s, pinging myself...');
						$this->sendCtcpReq($this->ircNick,'PING '.time(),0);
						$pingerSent = true;
					}

					if ((time() - $this->ircLastReceived) >= 35) {
						$this->__debug('|| INTERNAL: WARNING: Seems we\'re not connected... restarting...');
						if ($this->ircReconnect) { $this->connect(); } else { break; }
					}
				}
			}

			if ($tickerSleep <= $tickerMin) { $tickerSleep = $tickerMin; }
			if ($tickerSleep >= $tickerMax) { $tickerSleep = $tickerMax; }
			//if (!$this->ircLoggedIn) { $tickerSleep = 10000; }
			if ($this->ircLoggedIn) { usleep($tickerSleep); }

			if ($this->loopBreak) { $this->loopBreak = false; break; }
		}
	}

	#####################################
	#		INCOMING DATAS HANDLING		#
	#####################################

	protected function __rawReceive(&$Line)
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

		if (!is_numeric($priority) || $priority < 0 || $priority > 5)
		{
			$priority = 3;
		}

		if ($priority == 0)
		{
			//$this->__debug('|| INTERNAL: Sending '.$this->strBytesCounter($Line."\n").'bytes ('.strlen($Line."\n").'chars)');
			$this->__debug('>> '.$Line);
			$this->netSocket->write($Line."\n");
		} else { array_push($this->ircBuffers[$priority],$Line); }
	}

	protected function __checkBuffer()
	{
		if (!$this->ircLoggedIn) { return false; }
		foreach ($this->ircBuffers as &$buffer)
		{
			$Line = array_shift($buffer);
			if ($Line !== null) { $this->__send($Line,0); return true; }
		}
		return false;
	}

	public function __flushBuffer() { while ($this->__checkBuffer()) { sleep($this->tickerInterval); } }

	protected function __debug($x)
	{
		if (!$this->debugEnabled) { return false; }
		if ($this->netSocket instanceof netSocket && $this->netSocket->isOpen())
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

	public function __modeChange($_modes,$_m,$_b = true)
	{
		$mpos = strpos($_modes,$_m);

		if ($_b)
		{
			if ($mpos === false)
			{
				$_modes .= $_m;
			}
		} else
		{
			if ($mpos !== false)
			{
				$_modes = substr($_modes,0,$mpos).substr($_modes,$mpos+1);
			}
		}

		return $_modes;
	}

	#############################
	#		VARIOUS HELPERS		#
	#############################

	/**
	* Check if a string is a valid IRC mask (nick!ident@host)
	*
	* @param string $in The string to check
	* @param string $transform Tell if the function should return a bool or an array/string
	* @return mixed
	*/
	public function isMask($in,$transform = false)
	{
		$in = trim($in);

		if (!$transform)
		{
			return preg_match('#^(.+)!(.+)@(.+)$#',$in);
		}
		$m = array();
		if (preg_match('#^(.+)!(.+)@(.+)$#',$in,$m))
		{
			$res = new stdClass;
			$res->nick = $m[1];
			$res->ident = $m[2];
			$res->host = $m[3];
			$res->mask = $m[0];
		} else
		{
			$res = $in;
		}
		return $res;
	}

	/**
	* Check if a given user is on a specified channel
	*
	* @param string $_nick The nick to check
	* @param string $_channel The channel so check is $_nick is on
	* @return bool True is $_nick is on $_channel, false else
	*/
	public function isOn($_nick,$_channel)
	{
		if ($this->getChannelUser($_channel,$_nick) === false)
		{
			return false;
		}
		return true;
	}

	/**
	* Return the nick from an IRC mask (nick!ident@host)
	*
	* @param string $in An IRC mask
	* @return string The corresponding nick
	*/
	public function mask2nick($in)
	{
		$in = trim($in);
		$pos = strpos($in,'!');

		if ($pos !== false)
		{
			return substr($in,0,$pos);
		} else { return false; }
	}

	/**
	* Return the ident from an IRC mask (nick!ident@host)
	*
	* @param string $in An IRC mask
	* @return string The corresponding ident
	*/
	public function mask2ident($in)
	{
		// ab!cd@ef
		$in = trim($in);
		$pos = strpos($in,'!'); // 2
		$pos1 = strpos($in,'@'); // 5

		if ($pos !== false && $pos1 !== false)
		{
			return substr($in,$pos+1,$pos1-$pos-1);
		} else { return false; }
	}

	/**
	* Return the hostname from an IRC mask (nick!ident@host)
	*
	* @param string $in An IRC mask
	* @return string The corresponding host
	*/
	public function mask2host($in)
	{
		$in = trim($in);
		$pos = strpos($in,'@');

		if ($pos !== false)
		{
			return substr($in,$pos+1);
		} else { return false; }
	}


	/**
	* Verify is an IRC mask match another
	*
	* @param string $mask The mask to match
	* @param string $reg The matching expression, accepts * wildcard
	* @return bool
	*/
	public function matchMask($mask,$reg)
	{
		return preg_match('/'.str_replace('\*','(.+)',preg_quote($reg,'/')).'/',$mask);
	}

	/**
	* Strip mIRC color codes from a string.
	*
	* @param string $input The strip to strip
	* @return string The stripped text
	*/
	public function ircStripper($input)
	{
		return preg_replace("#\x16|\x1d|\x1f|\x02|\x03(?:\d{1,2}(?:,\d{1,2})?)?#",'',$input);
	}

	/**
	* Count the number of bytes of a given string.
	* Input string is expected to be ASCII or UTF-8 encoded.
	* Warning: the function doesn't return the number of chars
	* in the string, but the number of bytes.
	*
	* From http://fr.php.net/manual/function.strlen.php#72274
	*
	* @param string $str The string to compute number of bytes
	* @return integer The length in bytes of the given string.
	*/
	public function strBytesCounter($str)
	{
		$strlen_var = strlen($str);
		$d = 0;
		for ($c = 0; $c < $strlen_var; ++$c)
		{
			$ord_var_c = ord($str{$c});
			switch (true)
			{
				case (($ord_var_c >= 0x20) && ($ord_var_c <= 0x7F)):
					$d++;
				break;

				case (($ord_var_c & 0xE0) == 0xC0):
					$d+=2;
				break;

				case (($ord_var_c & 0xF0) == 0xE0):
					$d+=3;
				break;

				case (($ord_var_c & 0xF8) == 0xF0):
					$d+=4;
				break;

				case (($ord_var_c & 0xFC) == 0xF8):
					$d+=5;
				break;

				case (($ord_var_c & 0xFE) == 0xFC):
					$d+=6;
				break;

				default:
					$d++;
			}
		}
		return $d;
	}
}
?>
