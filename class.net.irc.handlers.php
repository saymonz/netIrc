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
 
class netIrc_Handlers extends netIrc_Commands {
	protected function __handle005($Line)
	{
		foreach ($Line->args as $arg)
		{
			if (strpos($arg,'=')) {
				$arg = explode('=',$arg);
				
				switch ($arg[0]) {
					case 'PREFIX':						
						$modes = str_split(substr($arg[1],1,strpos($arg[1],')')-1));
						$prefixes = str_split(substr($arg[1],strpos($arg[1],')')+1));
						
						while (true) {
							$prefix = array_shift($prefixes);
							$mode = array_shift($modes);
							
							if (!$prefix || !$mode) { break; }
							$this->ircNickPrefixes[$prefix] = $mode;
						}
					break;
					
					case 'CHANLIMIT':
						$_prefixes = split(',',$arg[1]);
						$prefixes = '';
						foreach ($_prefixes as $p)
						{
							$prefixes = substr($p,0,strpos($p,':'));
						}
						$this->ircChannelPrefixes = $prefixes;
					break;
					
					case 'CHANMODES':
						/*
						   o  Type A (0): Modes that add or remove an address to or from a list.
							  These modes always take a parameter when sent by the server to a
							  client; when sent by a client, they may be specified without a
							  parameter, which requests the server to display the current
							  contents of the corresponding list on the channel to the client.
						   o  Type B (1): Modes that change a setting on the channel.  These modes
							  always take a parameter.
						   o  Type C (2): Modes that change a setting on the channel. These modes
							  take a parameter only when set; the parameter is absent when the
							  mode is removed both in the client's and server's MODE command.
						   o  Type D (3): Modes that change a setting on the channel. These modes
							  never take a parameter.
						*/
						$this->ircChannelModes = explode(',',$arg[1]);
						
					break;
				}
			} else
			{
				switch ($arg)
				{
					case 'NAMESX':
						$this->__send('PROTOCTL NAMESX',0);
					break;	
						
					
					
				}
			}
			
		}
	}
	
	protected function __handle311($Line) // WHOIS first line
	{
		$User = $this->ircGetUser($Line->args[0]);
		if ($User === false) { return; }
		$User->nick = $Line->args[0];
		$User->ident = $Line->args[1];
		$User->host = $Line->args[2];
		$User->mask = $Line->args[0].'!'.$Line->args[1].'@'.$Line->args[2];
		$User->realname = implode(' ',array_slice($Line->message_xt,1));
	}
	
	protected function __handle324($Line) // Channel modes
	{
		$Line->target = array_shift($Line->args);
		$this->__handleMODE($Line);
	}
	
	protected function __handle332($Line) // Topic
	{
		$Channel = $this->ircGetChannel($Line->args[0]);
		$Channel->topic = $Line->message;
	}
	
	protected function __handle333($Line) // Topic by & time
	{
		$Channel = $this->ircGetChannel($Line->args[0]);
		$Channel->topic_by = $Line->args[1];
		$Channel->topic_time = $Line->args[2];
	}
	
	protected function __handle346($Line) // Invite exception list
	{
		$Channel = $this->ircGetChannel($Line->args[0]);
		if (!isset($Channel->lists['I']))
		{
			$Channel->lists['I'] = array();
		}

		if (!isset($this->ircChannels[$Line->args[0]]->lists['I'][$Line->args[1]]))
		{
			$Channel->lists['I'][$Line->args[1]] = new stdClass;
			$Channel->lists['I'][$Line->args[1]]->by = $Line->args[2];
			$Channel->lists['I'][$Line->args[1]]->time = $Line->args[3];
		}
	}
	
	protected function __handle348($Line) // Exception list
	{
		$Channel = $this->ircGetChannel($Line->args[0]);
		if (!isset($Channel->lists['e']))
		{
			$Channel->lists['e'] = array();
		}

		if (!isset($this->ircChannels[$Line->args[0]]->lists['e'][$Line->args[1]]))
		{
			$Channel->lists['e'][$Line->args[1]] = new stdClass;
			$Channel->lists['e'][$Line->args[1]]->by = $Line->args[2];
			$Channel->lists['e'][$Line->args[1]]->time = $Line->args[3];
		}
	}
	
	protected function __handle352($Line) // WHO
	{
		$User = $this->ircGetUser($Line->args[4]);
		if ($User === false) { return; }
		
		$User->ident = $Line->args[1];
		$User->host = $Line->args[2];
		$User->nick = $Line->args[4];
		$User->realname = implode(' ',array_slice($Line->message_xt,1));
		$User->mask = $User->nick.'!'.$User->ident.'@'.$User->host;
	}
	
	protected function __handle353($Line) // NAMES
	{
		$Channel = $this->ircGetChannel($Line->args[1]);
		if ($Channel === false) { return; }
		
		foreach ($Line->message_xt as $_user)
		{
			$_user_xt = str_split($_user);
			$_modes = '';
			$_nick = '';
			foreach ($_user_xt as $pos => $char)
			{
				if (isset($this->ircNickPrefixes[$char]))
				{
					$_modes .= $this->ircNickPrefixes[$char];
				} else {
					$_nick = implode(array_slice($_user_xt,$pos));
					break;
				}
			}
			
			$User = $this->ircGetUser($_nick);			
			$ChannelUser = $this->ircGetChannelUser($Line->args[1],$_nick);
			
			if ($User === false) {
				$this->ircUsers[] = $User = new netIrc_User;
				$User->nick = $_nick;
				$User->channels[] = $Channel;
			}
			
			if ($ChannelUser === false)
			{
				$Channel->users[] = $ChannelUser = new netIrc_ChannelUser;
				$ChannelUser->modes = $_modes;
				$ChannelUser->user = $User;
				
			}
		}
	}
	
	protected function __handle366($Line) // NAMES end
	{
		$this->sendRaw('WHO '.$Line->args[0],1);
		$modes = str_split($this->ircChannelModes[0]);
		foreach ($modes as $m) { $this->sendRaw('MODE '.$Line->args[0].' +'.$m,1); }
	}
	
	protected function __handle367($Line) // Banlist
	{
		$Channel = $this->ircGetChannel($Line->args[0]);
		if (!isset($Channel->lists['b'])) { $Channel->lists['b'] = array(); }
		
		if (!isset($Channel->lists['b'][$Line->args[1]]))
		{
			$Channel->lists['b'][$Line->args[1]] = new stdClass;
			$Channel->lists['b'][$Line->args[1]]->by = $Line->args[2];
			$Channel->lists['b'][$Line->args[1]]->time = $Line->args[3];
		}
		
	}
	
	protected function __handle372($Line) // MOTD lines
	{
		$this->ircMotd[] = $Line->message;
	}
	
	protected function __handle375($Line) // MOTD start
	{
		$this->ircMotd = array();
	}
	
	protected function __handle376($Line) // MOTD end
	{
		$this->ircLoggedIn = true;
		$this->netSocket->setBlocking(0);
	}
	
	protected function __handle386($Line) // Channel owners list
	{
		$ChannelUser = $this->ircGetChannelUser($Line->args[0],$Line->args[1]);
		if ($ChannelUser === false) { return; }
		if (strpos($ChannelUser->modes,'q') === false)
		{
			$ChannelUser->modes .= 'q';
		}
	}
	
	protected function __handle388($Line) // Channel administrators list
	{
		$ChannelUser = $this->ircGetChannelUser($Line->args[0],$Line->args[1]);
		if ($ChannelUser === false) { return; }
		if (strpos($ChannelUser->modes,'a') === false)
		{
			$ChannelUser->modes .= 'a';
		}
	}
	
	protected function __handle433($Line) // Nickname already in use
	{
		if (!$this->ircLoggedIn)
		{
			$this->ircNick = $this->ircNick.'`';
			$this->sendNick($this->ircNick,0);
		}
	}
	
	protected function __handleCTCPREQ($Line)
	{
		if ($Line->message_xt[0] == 'VERSION')
		{
			$this->sendCtcpRep($Line->source->nick,'VERSION PHP NetIrc by Saymonz');
		}
		if ($Line->message_xt[0] == 'PING')
		{
			if (isset($Line->message_xt[1])) 
			{
				$this->sendCtcpRep($Line->source->nick,'PING '.$Line->message_xt[1]);
			} else {
				$this->sendCtcpRep($Line->source->nick,'PING');
			}
		}
	}
	
	protected function __handleERROR($Line)
	{
		if ($this->ircReconnect) { $this->connect(); }
	}
	
	protected function __handleJOIN($Line)
	{
		if ($Line->source->nick == $this->ircNick)
		{
			$this->ircChannels[] = $Channel = new netIrc_Channel;
			$Channel->name = $Line->message;
		}
		
		$User = $this->ircGetUser($Line->source->nick);
		if ($User === false) { $this->ircUsers[] = $User = new netIrc_User; }
		if (!isset($Channel)) { $Channel = $this->ircGetChannel($Line->message); }
		
		$User->nick = $Line->source->nick;
		$User->ident = $Line->source->ident;
		$User->host = $Line->source->host;
		$User->mask = $Line->source->mask;
		$User->channels[] = $Channel;
		
		$Channel->users[] = $ChannelUser = new netIrc_ChannelUser;
		$ChannelUser->user = $User;
	}
	
	protected function __handleKICK($Line)
	{
		if ($Line->args[0] == $this->ircNick)
		{
			$this->ircCleanChannel($Line->target);
			$this->sendJoin($Line->target);
		} else {
			$this->ircCleanChannelUser($Line->target,$Line->args[0]);
		}
	}
	
	protected function __handleMODE($Line)
	{
		if ($Line->target_ischannel)
		{
			$Channel = $this->ircGetChannel($Line->target);
			$modes = str_split(array_shift($Line->args));
			foreach ($modes as $mode)
			{
				switch ($mode)
				{
					case '+':
						$m = true;
					break;
					
					case '-':
						$m = false;
					break;
					
					default:
						if (in_array($mode,$this->ircNickPrefixes)) // mode utilisateur préfixé
						{
							$nick = array_shift($Line->args);
							$channelUser = $this->ircGetChannelUser($Line->target,$nick);
							$um = $channelUser->modes;
							
							if ($m)
							{
								if (strpos($um,$mode) === false)
								{
									$um .= $mode;
								}
							} else {
								$p = strpos($um,$mode);
								if ($p !== false)
								{
									$um = substr($um,0,$p).substr($um,$p+1);
								}
							}
							$channelUser->modes = $um;
						} else {
							foreach ($this->ircChannelModes as $k => $v)
							{
								if (strpos($v,$mode) !== false) { break; }
							}

							switch ($k)
							{
								case 0:
									$m_target = array_shift($Line->args);
									if ($this->ircIsMask($m_target)) {
										$m_target = strtolower($m_target);
										if ($m)
										{
											$Channel->lists[$mode][$m_target] = new stdClass;
											$Channel->lists[$mode][$m_target]->by = $Line->source->nick;
											$Channel->lists[$mode][$m_target]->time = time();
										} else
										{
											if (isset($Channel->lists[$mode][$m_target]))
											{
												unset($Channel->lists[$mode][$m_target]);
											}
										}
									} else
									{
										$channelUser = $this->ircGetChannelUser($Line->target,$m_target);
										$um = $channelUser->modes;
										
										if ($m)
										{
											$um .= $mode;
										} else {
											$p = strpos($um,$mode);
											$um = substr($um,0,$p).substr($um,$p+1);
										}
										$channelUser->modes = $um;
									}
								break;
									
									
								case 1:
									$arg = array_shift($Line->args); // Cat. B, always shift a parameter
									
									if ($m)
									{
										$Channel->modes[$mode] = $arg;
									} else
									{
										unset($Channel->modes[$mode]);
									}
								break;
								
								case 2:
									if ($m)
									{
										$arg = array_shift($Line->args);
										$Channel->modes[$mode] = $arg;
									} else
									{
										unset($Channel->modes[$mode]);
									}
								break;
								
								case 3:
									if ($m)
									{
										$Channel->modes[$mode] = true;
									} else
									{
										unset($Channel->modes[$mode]);
									}
								break;
								
								default:
									/* 
									 * If the server sends any additional types after these 4, the client
									 * MUST ignore them; this is intended to allow future extension of this
									 * token.
									 * 
									 * -> DO NOTHING
									 */
								break;
							}
						}
					break;
				}
			}
		}
	}
	
	protected function __handleNICK($Line)
	{
		print_r($Line);
		if ($Line->source->nick == $this->ircNick) { $this->ircNick = $Line->message; } // Own nick change
		foreach ($this->ircChannels as &$channel)
		{
			if (isset($channel->users[$Line->source->nick]))
			{
				$channel->users[$Line->source->nick]->nick = $Line->message;
				$channel->users[$Line->message] = $channel->users[$Line->source->nick];
				unset($channel->users[$Line->source->nick]);
			}
		}
	}
	
	protected function __handleNOTICEAUTH($Line)
	{
		if (!$this->ircLoginSent)
		{
			$this->sendNick($this->ircNick,0);
			$this->sendUser($this->ircIdent,$this->ircRealname);
			$this->ircLoginSent = true;
		}
	}
	
	protected function __handlePART($Line)
	{
		if ($Line->source->nick == $this->ircNick)
		{
			$this->ircCleanChannel($Line->target);
		} else {
			$this->ircCleanChannelUser($Line->target,$Line->source->nick);
		}
	}
	
	protected function __handlePING($Line)
	{
		$this->__send('PONG :'.$Line->message_xt[0],0);
	}
	
	protected function __handleQUIT($Line)
	{
		$this->ircCleanUser($Line->source->nick);
	}
	
	protected function __handleTOPIC($Line)
	{
		$Channel = $this->ircGetChannel($Line->target);
		$Channel->topic_by = $Line->source->nick;
		$Channel->topic = $Line->args[0];
		$Channel->topic_time = time();
	}
	



}
