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
	protected $ircChannels = null;			# Channels joined
	protected $ircMotd = null;				# Server MOTD
	protected $ircLoggedIn = false;			# Connected or not
	protected $ircBuffers = null;			# Send queues
	protected $ircLine = null;				# Last line read from the server
	protected $ircChannelModes = array();	# Channel modes
	protected $ircNickPrefixes = array();	# Nicknames prefixes
	protected $ircLastReceived = null;		# Last received time
	protected $ircLoginSent = false;
	protected $ircReconnect = true;
	
	// Internal
	protected $eventHandlers = array();		# Event handlers
	protected $debugEnabled = true;			# Debug to stdout or not

	// Ticker
	protected $tickerInterval = 0;			# Actual ticker interval
	protected $tickerMax = 500000;			# Max ticker interval
	protected $tickerMin = 10000;			# Min ticker interval
	protected $tickerInc = 10000;			# Ticker incrementation
	
	
	public function __construct($host,$port,$nick,$ident,$realname) 
	{
		// Setting up vars...
		$this->ircHost = $host;
		$this->ircPort = (int) $port;
		$this->ircNick = $nick;
		$this->ircIdent = $ident;
		$this->ircRealname = $realname;
		
		// Setting up sending queues
		$this->ircBuffers = range(1,6);
		foreach ($this->ircBuffers as &$v) { $v = array(); }
		
		// Setting up handlers
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
		} else {
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
	
	protected function __callHandler($type,$data)
	{
		if (is_callable(array($this,'__handle'.$type)))
		{
			$this->__debug('|| INTERNAL: Calling internal handler for '.$type);
			call_user_func(array($this,'__handle'.$type),$data);
		}
		
		if (isset($this->eventHandlers[$type]))
		{
			foreach ($this->eventHandlers[$type] as $k => &$v)
			{
				if (is_callable($v['callback']))
				{
					if (is_null($v['regex']) || !isset($data->message_stripped) || preg_match($v['regex'],$data->message_stripped))
					{
						$this->__debug('|| INTERNAL: Calling external handler '.$k.' for '.$type);
						call_user_func($v['callback'],$this,$data);
					}
				} else {
					$this->__debug('|| INTERNAL: WARNING: invalid callback '.$k.' for '.$type);
				}
			}
		}
		return true;
	}
	
	#################################
	#			Getters				#
	#################################
	
	public function getNick() { return $this->ircNick; }
	
	public function getMotd() { return $this->ircMotd; }
	
	public function getChannels($channel = null) { 
		if ($channel == null) { return $this->ircChannels; }
		if (isset($this->ircChannels[$channel])) { return $this->ircChannels[$channel]; }
		return false;
	}
	
	public function deconnect($msg = null) {
		$this->ircReconnect = false;
		$this->__flushBuffer();
		$this->sendQuit($msg);
	}
	
	#################################
	#		MAIN LOOP FUNCTION		#
	#################################
	
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
		}
	}
	
	public function connect() {
		$this->ircLoggedIn = false;
		$this->ircLoginSent = false;
		unset($this->netSocketIterator);
		unset($this->netSocket);
		
		$this->netSocket = new netSocket($this->ircHost,$this->ircPort);
		$this->netSocketIterator = $this->netSocket->open();
		return true;
	}
	
	protected function __rawReceive(&$data)
	{
		$parsed = $this->__ircParser($data);
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
			$match[1] = $this->__arrayTrimmer($match[1]);

			$res->source = $this->ircIsMask(array_shift($match[1]),true);
			
			
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
			$match[1] = $this->__arrayTrimmer($match[1]);

			$res->source = $this->ircIsMask(array_shift($match[1]),true);
						
						
			$res->command = array_shift($match[1]);
			$res->target = array_shift($match[1]);
			
			$res->args = $match[1];
		} elseif (preg_match('#^(.+):(.*)$#U',$in,$match))
		{
			$match[1] = explode(' ',$match[1]);
			$match[1] = $this->__arrayTrimmer($match[1]);
			
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
			$res->command = 'NOTICEAUTH';
		}
		return $res;
	}
	
	#################################
	#		SEND QUEUES FUNCS		#
	#################################
	
	protected function __send($data,$priority = 3)
	{
		$data = trim(text::toUTF8($data));
		if ($data == null) { return; }
		
		if (!is_numeric($priority) || $priority < 0 || $priority > 5)
		{
			$priority = 3;
		}
		
		if ($priority == 0)
		{
			//$this->__debug('|| INTERNAL: Sending '.$this->strBytesCounter($data."\n").'bytes ('.strlen($data."\n").'chars)');
			$this->__debug('>> '.$data);
			$this->netSocket->write($data."\n");
		} else { array_push($this->ircBuffers[$priority],$data); }
	}
	
	protected function __checkBuffer()
	{
		if (!$this->ircLoggedIn) { return false; }
		foreach ($this->ircBuffers as &$buffer)
		{
			$data = array_shift($buffer);
			if ($data !== null) { $this->__send($data,0); return true; }
		}
		return false;
	}
	
	public function __flushBuffer() { while ($this->__checkBuffer()) { sleep($this->tickerInterval); } }
	
	/*
	* IRC Special Chars :
	* 	CTCP delimiter // We don't strip it, used to detect CTCPs and ACTIONs
	* 	Bold
	* 	Colors
	* 	Reverse
	* 	Underlined
	* 	Italic
	* 
	* Original regex: (don't remember where it came from)
	* #\x0f|\x1f|\x02|\x03(?:\d{1,2}(?:,\d{1,2})?)?# 
	*/
		
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
	
	###########
	# HELPERS #
	###########
	
	public function ircIsMask($in,$transform = false)
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
	
	public function ircIsOn($nick,$channel) {
		if (isset($this->ircChannels[$channel]) && isset($this->ircChannels[$channel]->users[$nick]))
		{
			return $this->ircChannels[$channel]->users[$nick];
		} else { return false; }
	}
	
	public function ircMask2Nick($in)
	{
		$in = trim($in);
		$pos = strpos($in,'!');
		
		if ($pos !== false)
		{
			return substr($in,0,$pos);
		} else { return false; }
	}
	
	public function ircMask2Ident($in)
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
	
	public function ircMask2Host($in)
	{
		$in = trim($in);
		$pos = strpos($in,'@');
		
		if ($pos !== false)
		{
			return substr($in,$pos+1);
		} else { return false; }
	}
	
	public function matchMask($mask,$reg)
	{
		return preg_match('/'.str_replace('\*','(.+)',preg_quote($reg,'/')).'/',$mask);
	}
	
	public function ircStripper($input) { return preg_replace("#\x16|\x1d|\x1f|\x02|\x03(?:\d{1,2}(?:,\d{1,2})?)?#",'',$input); }
	
	protected function __arrayTrimmer($in)
	{
		$res = array();
		foreach ($in as $v)
		{
			$v = trim($v);
			if ($v !== '') { $res [] = $v; }
		}
		return $res;
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
     * 
     * @return The length in bytes of the given string.
     */ 
	protected function strBytesCounter($str) { $strlen_var = strlen($str); $d = 0; for ($c = 0; $c < $strlen_var; ++$c) { $ord_var_c = ord($str{$c});switch (true) { case (($ord_var_c >= 0x20) && ($ord_var_c <= 0x7F)): $d++; break; case (($ord_var_c & 0xE0) == 0xC0): $d+=2; break; case (($ord_var_c & 0xF0) == 0xE0): $d+=3; break; case (($ord_var_c & 0xF8) == 0xF0): $d+=4; break; case (($ord_var_c & 0xFC) == 0xF8): $d+=5; break; case (($ord_var_c & 0xFE) == 0xFC): $d+=6; break; default: $d++; }} return $d; }
}
?>
