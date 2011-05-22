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

class netIrc_Commands extends netIrc_Helpers {
	public function sendAction($target,$message,$priority = 3)
	{
		$target = trim($target);
		if ($target == '' || trim($message) == '') { return false; }

		$this->__send('PRIVMSG '.$target.' :ACTION '.$message.'',$priority);
		return true;
	}

	public function sendCtcpRep($target,$message,$priority = 3)
	{
		$target = trim($target);
		if ($target == '' || trim($message) == '') { return false; }

		$this->__send('NOTICE '.$target.' :'.$message.'',$priority);
		return true;
	}

	public function sendCtcpReq($target,$message,$priority = 3)
	{
		$target = trim($target);
		if ($target == '' || trim($message) == '') { return false; }

		$this->__send('PRIVMSG '.$target.' :'.$message.'',$priority);
		return true;
	}

	public function sendJoin($channel,$key = null,$priority = 2)
	{
		$channel = trim($channel);
		if ($channel == '') { return false; }

		$this->__send('JOIN '.$channel.' '.$key,$priority);
		return true;
	}

	public function sendNick($newnick,$priority = 3)
	{
		$newnick = trim($newnick);
		if ($newnick == '') { return false; }

		$this->__send('NICK '.$newnick,$priority);
		return true;
	}

	public function sendNotice($target,$message,$priority = 3)
	{
		$target = trim($target);
		if ($target == '' || trim($message) == '') { return false; }

		$this->__send('NOTICE '.$target.' :'.$message,$priority);
		return true;
	}
	
	public function sendPart($channel,$priority = 4)
	{
		$channel = trim($channel);
		if ($channel == '') { return false; }

		$this->__send('PART '.$channel,$priority);
		return true;
	}

	public function sendPrivmsg($target,$message,$priority = 3)
	{
		$target = trim($target);
		if ($target == '' || trim($message) == '') { return false; }

		$this->__send('PRIVMSG '.$target.' :'.$message,$priority);
		return true;
	}

	public function sendQuit($msg = null,$priority = 5)
	{
		if ($msg == null || trim($msg) == '')
		{
			$this->__send('QUIT',$priority);
		} else
		{
			$this->__send('QUIT :'.$msg,$priority);
		}
		return true;
	}

	public function sendRaw($raw,$priority = 1)
	{
		if (trim($raw) == '') { return false; }

		$this ->__send($raw,$priority);
		return true;
	}

	public function sendUser($ident,$realname,$priority = 0)
	{
		$ident = trim($ident);
		if ($ident == '' || trim($realname) == '') { return false; }

		$this->__send('USER '.$ident.' - - :'.$realname,$priority);
		return true;
	}
}
