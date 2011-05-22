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

class netIrc extends netIrc_Handlers {
	/*
	 * This class is here just to have a beautiful name for the object.
	 * By the extends magic, PHP will load:
	 * - netIrc_Handlers containing all internal handlers which allows
	 * the class to maintain an IRC connection alive among other things.
	 * - netIrc_Commands containing all the send*(); functions, for
	 * interacting with the server.
	 * - netIrc_Helpers containing various helper functions.
	 * - netIrc_Base containing the core of the class (the main listen()
	 * function for example) among other things (various helpers, etc.),
	 * this is where every public functions are.
	 *
	 * This separation is just for code clarification purpose.
	 */
}

class netIrc_Line {
	public $source;
	public $command;
	public $target;
	public $target_ischannel = false;
	public $args = array();
	public $message;
	public $message_xt = array();
	public $message_stripped;
	public $message_stripped_xt = array();
	public $raw;
}

class netIrc_Channel {
	public $name;
	public $topic;
	public $topic_by;
	public $topic_time;
	public $modes = array();
	public $users = array();
	public $lists = array();
}

class netIrc_User {
	public $nick;
	public $ident;
	public $host;
	public $mask;
	public $realname;
	public $channels = array();
}

class netIrc_ChannelUser {
	public $user;
	public $modes;
}
?>
