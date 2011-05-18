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
	protected function __handle005($data)
	{
		/*
		[0] => CMDS=KNOCK,MAP,DCCALLOW,USERIP
		[1] => NAMESX
		[2] => SAFELIST
		[3] => HCN
		[4] => MAXCHANNELS=20
		[5] => CHANLIMIT=#:20
		[6] => MAXLIST=b:60,e:60,I:60
		[7] => NICKLEN=30
		[8] => CHANNELLEN=32
		[9] => TOPICLEN=307
		[10] => KICKLEN=307
		[11] => AWAYLEN=307
		[12] => MAXTARGETS=20
		[13] => are supported by this server

		[0] => WALLCHOPS
		[1] => WATCH=128
		[2] => SILENCE=15
		[3] => MODES=12
		[4] => CHANTYPES=#
		[5] => PREFIX=(ohv)@%+
		[6] => CHANMODES=beIqa,kfL,lj,psmntirRcOAQKVCuzNSMTG
		[7] => NETWORK=EpiKnet
		[8] => CASEMAPPING=ascii
		[9] => EXTBAN=~,cqnr
		[10] => ELIST=MNUCT
		[11] => STATUSMSG=@%+
		[12] => EXCEPTS
		[13] => are supported by this server

		[0] => INVEX
		[1] => are supported by this server
		*/
		foreach ($data->args as $arg)
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
	
	protected function __handle311($data)
	{
		foreach ($this->ircChannels as &$channel)
		{
			if (isset($channel->users[$data->args[0]]))
			{
				$channel->users[$data->args[0]]->nick = $data->args[0];
				$channel->users[$data->args[0]]->ident = $data->args[1];
				$channel->users[$data->args[0]]->host = $data->args[2];
				$channel->users[$data->args[0]]->mask = $data->args[0].'!'.$data->args[1].'@'.$data->args[2];
				$channel->users[$data->args[0]]->realname = implode(' ',array_slice($data->message_xt,1));
			}
		}
	}
	
	protected function __handle324($data)
	{
		// Let's get tricky...
		//$this->ircChannels[$data->args[0]]->modes = substr($data->args[1],1);
		$data->target = array_shift($data->args);
		$this->__handleMODE($data);
	}
	
	protected function __handle332($data)
	{
		if (isset($this->ircChannels[$data->args[0]]))
		{
			$this->ircChannels[$data->args[0]]->topic = $data->message;
		}
	}
	
	protected function __handle333($data)
	{
		$this->ircChannels[$data->args[0]]->topic_by = $data->args[1];
		$this->ircChannels[$data->args[0]]->topic_time = $data->args[2];
	}
	
	protected function __handle346($data)
	{
		$data->args[1] = strtolower($data->args[1]);
		if (!isset($this->ircChannels[$data->args[0]]->lists['I']))
		{
			$this->ircChannels[$data->args[0]]->lists['I'] = array();
		}

		if (!isset($this->ircChannels[$data->args[0]]->lists['I'][$data->args[1]]))
		{
			$this->ircChannels[$data->args[0]]->lists['I'][$data->args[1]] = new stdClass;
			$this->ircChannels[$data->args[0]]->lists['I'][$data->args[1]]->by = $data->args[2];
			$this->ircChannels[$data->args[0]]->lists['I'][$data->args[1]]->time = $data->args[3];
		}
	}
	
	protected function __handle348($data)
	{
		$data->args[1] = strtolower($data->args[1]);
		if (!isset($this->ircChannels[$data->args[0]]->lists['e']))
		{
			$this->ircChannels[$data->args[0]]->lists['e'] = array();
		}

		if (!isset($this->ircChannels[$data->args[0]]->lists['b'][$data->args[1]]))
		{
			$this->ircChannels[$data->args[0]]->lists['e'][$data->args[1]] = new stdClass;
			$this->ircChannels[$data->args[0]]->lists['e'][$data->args[1]]->by = $data->args[2];
			$this->ircChannels[$data->args[0]]->lists['e'][$data->args[1]]->time = $data->args[3];
		}
	}
	
	protected function __handle352($data)
	{
		$user = $this->ircGetUser($data->args[4]);
		if ($user === false) { return; }
		
		$user->ident = $data->args[1];
		$user->host = $data->args[2];
		$user->nick = $data->args[4];
		$user->realname = implode(' ',array_slice($data->message_xt,1));
		
		$user->mask = $user->nick.'!'.$user->ident.'@'.$user->host;
	}
	
	protected function __handle353($data)
	{
		$users = $data->message_xt;
		$channel = $data->args[1];
		foreach ($users as $user)
		{
			$_user = str_split($user);
			$_modes = '';
			$nick = '';
			foreach ($_user as $pos => $char)
			{
				if (isset($this->ircNickPrefixes[$char]))
				{
					$_modes .= $this->ircNickPrefixes[$char];
				} else {
					$nick = implode(array_slice($_user,$pos));
					break;
				}
			}
			
			$userObj = $this->ircGetUser($nick);
			$channelUser = $this->ircGetChannelUser($channel,$nick);
			if ($userObj === false) { $this->ircUsers[] = $userObj = new netIrc_User; }
			if ($channelUser === false) { $this->ircChannels[$channel]->users[] = $channelUser = new netIrc_ChannelUser; };
			$userObj->nick = $nick;
			$channelUser->user = $userObj;
			$channelUser->modes = $_modes;
			$userObj->channels[$channel] = $this->ircChannels[$channel];
		}
	}
	
	protected function __handle366($data)
	{
		$this->sendRaw('WHO '.$data->args[0],1);
	}
	
	protected function __handle367($data)
	{
		$data->args[1] = strtolower($data->args[1]);
		if (!isset($this->ircChannels[$data->args[0]]->lists['b']))
		{
			$this->ircChannels[$data->args[0]]->lists['b'] = array();
		}
		
		if (!isset($this->ircChannels[$data->args[0]]->lists['b'][$data->args[1]]))
		{
			$this->ircChannels[$data->args[0]]->lists['b'][$data->args[1]] = new stdClass;
			$this->ircChannels[$data->args[0]]->lists['b'][$data->args[1]]->by = $data->args[2];
			$this->ircChannels[$data->args[0]]->lists['b'][$data->args[1]]->time = $data->args[3];
		}
		
	}
	
	protected function __handle372($data) // MOTD lines
	{
		$this->ircMotd[] = $data->message;
	}
	
	protected function __handle375($data) // MOTD start
	{
		$this->ircMotd = array();
	}
	
	protected function __handle376($data)
	{
		$this->ircLoggedIn = true;
		$this->netSocket->setBlocking(0);
	}
	
	protected function __handle386($data)
	{
		if (!isset($this->ircChannels->users[$data->args[1]])) { return; }
		if (strpos($this->ircChannels->users[$data->args[1]]->modes,'q') === false)
		{
			$this->ircChannels->users[$data->args[1]]->modes .= 'q';
		}
	}
	
	protected function __handle388($data)
	{
		if (!isset($this->ircChannels->users[$data->args[1]])) { return; }
		if (strpos($this->ircChannels->users[$data->args[1]]->modes,'a') === false)
		{
			$this->ircChannels->users[$data->args[1]]->modes .= 'a';
		}
	}
	
	protected function __handle433($data)
	{
		if (!$this->ircLoggedIn)
		{
			$this->ircNick = $this->ircNick.'`';
			$this->sendNick($this->ircNick,0);
		}
	}
	
	protected function __handleCTCPREQ($data)
	{
		if ($data->message_xt[0] == 'VERSION')
		{
			$this->sendCtcpRep($data->source->nick,'VERSION PHP NetIrc by Saymonz');
		}
		if ($data->message_xt[0] == 'PING')
		{
			if (isset($data->message_xt[1])) 
			{
				$this->sendCtcpRep($data->source->nick,'PING '.$data->message_xt[1]);
			} else {
				$this->sendCtcpRep($data->source->nick,'PING');
			}
		}
	}
	
	protected function __handleERROR($data)
	{
		if ($this->ircReconnect) { $this->connect(); $this->listen(); exit(); }
	}
	
	protected function __handleJOIN($data)
	{
		if ($data->source->nick == $this->ircNick)
		{
			$this->ircChannels[$data->message] = new netIrc_Channel;
			
			$this->sendRaw('MODE '.$data->message,1);
			$modes = str_split($this->ircChannelModes[0]);
			foreach ($modes as $m) { $this->sendRaw('MODE '.$data->message.' +'.$m,1); }
		}
		
		$user = $this->ircGetUser($data->source->nick);
		if ($user === false) { $this->ircUsers[] = $user = new netIrc_User; }
		$user->nick = $data->source->nick;
		$user->ident = $data->source->ident;
		$user->host = $data->source->host;
		$user->mask = $data->source->mask;
		$user->channels[$data->message] = $this->ircChannels[$data->message];
		
		$this->ircChannels[$data->message]->users[] = $channelUser = new netIrc_ChannelUser;
		$channelUser->user = $user;
	}
	
	protected function __handleKICK($data)
	{
		if ($data->args[0] == $this->ircNick)
		{
			unset($this->ircChannels[$data->target]);
			$this->sendJoin($data->target);
		} else { unset($this->ircChannels[$data->target]->user[$data->args[0]]); }
	}
	
	protected function __handleMODE($data)
	{
		if (substr($data->target,0,1) == '#') # FIXME
		{
			$modes = str_split(array_shift($data->args));
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
							$nick = array_shift($data->args);
							$channelUser = $this->ircGetChannelUser($data->target,$nick);
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
									$m_target = array_shift($data->args);
									if ($this->ircIsMask($m_target)) {
										$m_target = strtolower($m_target);
										if ($m)
										{
											$this->ircChannels[$data->target]->lists[$mode][$m_target] = new stdClass;
											$this->ircChannels[$data->target]->lists[$mode][$m_target]->by = $data->source->nick;
											$this->ircChannels[$data->target]->lists[$mode][$m_target]->time = time();
										} else
										{
											if (isset($this->ircChannels[$data->target]->lists[$mode][$m_target]))
											{
												unset($this->ircChannels[$data->target]->lists[$mode][$m_target]);
											}
										}
									} else
									{
										$channelUser = $this->ircGetChannelUser($data->target,$m_target);
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
									$arg = array_shift($data->args); // Cat. B, always shift a parameter
									
									if ($m)
									{
										$this->ircChannels[$data->target]->modes[$mode] = $arg;
									} else
									{
										unset($this->ircChannels[$data->target]->modes[$mode]);
									}
								break;
								
								case 2:
									if ($m)
									{
										$arg = array_shift($data->args);
										$this->ircChannels[$data->target]->modes[$mode] = $arg;
									} else
									{
										unset($this->ircChannels[$data->target]->modes[$mode]);
									}
								break;
								
								case 3:
									if ($m)
									{
										$this->ircChannels[$data->target]->modes[$mode] = true;
									} else
									{
										unset($this->ircChannels[$data->target]->modes[$mode]);
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
	
	protected function __handleNICK($data)
	{
		print_r($data);
		if ($data->source->nick == $this->ircNick) { $this->ircNick = $data->message; } // Own nick change
		foreach ($this->ircChannels as &$channel)
		{
			if (isset($channel->users[$data->source->nick]))
			{
				$channel->users[$data->source->nick]->nick = $data->message;
				$channel->users[$data->message] = $channel->users[$data->source->nick];
				unset($channel->users[$data->source->nick]);
			}
		}
	}
	
	protected function __handleNOTICEAUTH($data)
	{
		if (!$this->ircLoginSent)
		{
			$this->sendNick($this->ircNick,0);
			$this->sendUser($this->ircIdent,$this->ircRealname);
			$this->ircLoginSent = true;
		}
	}
	
	protected function __handlePART($data)
	{
		if ($data->source->nick == $this->ircNick)
		{
			unset($this->ircChannels[$data->target]);
		} else {
			unset($this->ircChannels[$data->target]->users[$data->source->nick]);
		}
	}
	
	protected function __handlePING($data)
	{
		$this->__send('PONG :'.$data->message_xt[0],0);
	}
	
	protected function __handleQUIT($data)
	{
		foreach ($this->ircChannels as &$channel)
		{
			if (isset($channel->users[$data->source->nick]))
			{
				unset($channel->users[$data->source->nick]);
			}
		}
	}
	
	protected function __handleTOPIC($data)
	{
		$this->ircChannels[$data->target]->topic_by = $data->source->nick;
		$this->ircChannels[$data->target]->topic = $data->args[0];
		$this->ircChannels[$data->target]->topic_time = time();
	}
	



}
