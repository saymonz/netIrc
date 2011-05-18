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
	 * - netIrc_Base conteining the core of the class (the mail listen()
	 * function for example) among other things (various halpers, etc.),
	 * that's where every public functions are.
	 * 
	 * This separation is juste for code clarification purpose.
	 */
}

class netIrc_Line { // Dummy class for each IRC line
	public $source;
	public $command;
	public $target;
	public $args = array();
	public $message;
	public $message_xt = array();
	public $message_stripped;
	public $message_stripped_xt = array();
	public $raw;
}

class netIrc_Channel { // Dummy class for channel informations
	public $topic;
	public $topic_by;
	public $topic_time;
	public $modes = array();
	public $users = array();
	public $lists = array();
}

class netIrc_User { // Dummy class for user informations
	public $nick;
	public $ident;
	public $host;
	public $mask;
	public $realname;
	public $modes;
}
?>
