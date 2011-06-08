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

class netIrc_Helpers extends netIrc_Base
{
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
	* Check if a string is a valid IRC mask (nick!ident@host)
	*
	* @param string $in The string to check
	* @param string $transform Tell if the function should return a bool or an array/string
	* @return mixed An object representing the informations or a string or a boolean
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
	* @return bool True is $_nick is on $_channel or false
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
	* Return the hostname from an IRC mask (nick!ident@host)
	*
	* @param string $in An IRC mask
	* @return string The corresponding host or false
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
	* Return the ident from an IRC mask (nick!ident@host)
	*
	* @param string $in An IRC mask
	* @return string The corresponding ident or false
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
	* Return the nick from an IRC mask (nick!ident@host)
	*
	* @param string $in An IRC mask
	* @return string The corresponding nick or false
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
	* Add or remove a character from a string (used for modes handling)
	*
	* @param string $_modes Modes
	* @param string $_m Mode to add/remove
	* @param boolean $_m True to add, false to remove
	* @return string $_modes Modified modes string
	*/
	public function modeChange($_modes,$_m,$_b = true)
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

	public function print_channel($_channel)
	{
		$Channel = $this->getChannel($_channel);
		if (!$Channel instanceof netIrc_Channel) { return false; }
		$Channel = clone $Channel;

		$_channelusers = array();
		foreach ($Channel->users as $ChannelUser)
		{
			$_channelusers[] = $ChannelUser->user->nick;
		}

		$Channel->users = $_channelusers;
		print_r($Channel);
		return true;
	}

	public function print_user($_user)
	{
		$User = $this->getUser($_user);
		if (!$User instanceof netIrc_User) { return false; }
		$User = clone $User;

		$_channels = array();
		foreach ($User->channels as $Channel)
		{
			$_channels[] = $Channel->name;
		}

		$User->channels = $_channels;
		print_r($User);
		return true;
	}

	/**
	* Count the number of bytes of a given string.
	* Input string is expected to be ASCII or UTF-8 encoded.
	* Warning: the function doesn't return the number of chars
	* in the string, but the number of bytes.
	*
	* From http://fr.php.net/manual/function.strlen.php#72274
	*
	* @param string $in The string to compute number of bytes
	* @return integer The length in bytes of the given string.
	*/
	public function strBytesCounter($in)
	{
		$inlen_var = strlen($in);
		$d = 0;
		for ($c = 0; $c < $inlen_var; ++$c)
		{
			$ord_var_c = ord($in{$c});
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
