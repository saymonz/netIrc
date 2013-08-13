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

class netIrc_Handlers extends netIrc_Commands
{
    protected function __handle005($Line)
    {
        foreach ($Line->args as $arg) {
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
                            $this->irc_nicknamePrefixes[$prefix] = $mode;
                        }
                    break;

                    case 'CHANLIMIT':
                        $_prefixes = explode(',',$arg[1]);
                        $prefixes = '';
                        foreach ($_prefixes as $p) {
                            $prefixes = substr($p,0,strpos($p,':'));
                        }
                        $this->irc_channel_prefixes = $prefixes;
                    break;

                    case 'CHANMODES':
                        $this->irc_channel_modes = explode(',',$arg[1]);

                    break;
                }
            } else {
                switch ($arg) {
                    case 'NAMESX':
                        $this->__send('PROTOCTL NAMESX',0);
                    break;
                }
            }

        }
    }

    protected function __handle324($Line) // Channel modes
    {
        $Line = clone $Line;
        $Line->target = array_shift($Line->args);
        $Line->target_ischannel = true;
        $this->__handleMODE($Line);
    }

    protected function __handle332($Line) // Topic
    {
        $Channel = $this->getChannel($Line->args[0]);
        $Channel->topic = $Line->message;
    }

    protected function __handle333($Line) // Topic by & time
    {
        $Channel = $this->getChannel($Line->args[0]);
        $Channel->topic_by = $Line->args[1];
        $Channel->topic_time = $Line->args[2];
    }

    protected function __handle346($Line) // Invite exception list
    {
        $Channel = $this->getChannel($Line->args[0]);
        if (!isset($Channel->lists['I'])) {
            $Channel->lists['I'] = array();
        }

        if (!isset($Channel->lists['I']->lists['I'][$Line->args[1]])) {
            $Channel->lists['I'][$Line->args[1]] = new stdClass;
            $Channel->lists['I'][$Line->args[1]]->by = $Line->args[2];
            $Channel->lists['I'][$Line->args[1]]->time = $Line->args[3];
        }
    }

    protected function __handle348($Line) // Exception list
    {
        $Channel = $this->getChannel($Line->args[0]);
        if (!isset($Channel->lists['e'])) {
            $Channel->lists['e'] = array();
        }

        if (!isset($Channel->lists['I']->lists['e'][$Line->args[1]])) {
            $Channel->lists['e'][$Line->args[1]] = new stdClass;
            $Channel->lists['e'][$Line->args[1]]->by = $Line->args[2];
            $Channel->lists['e'][$Line->args[1]]->time = $Line->args[3];
        }
    }

    protected function __handle352($Line) // WHO
    {
        $User = $this->getUser($Line->args[4]);
        if ($User === false) { return; }

        $User->ident = $Line->args[1];
        $User->host = $Line->args[2];
        $User->nick = $Line->args[4];
        $User->realname = implode(' ',array_slice($Line->message_xt,1));
        $User->mask = $User->nick.'!'.$User->ident.'@'.$User->host;
    }

    protected function __handle353($Line) // NAMES
    {
        $Channel = $this->getChannel($Line->args[1]);
        if ($Channel === false) { return; }

        foreach ($Line->message_xt as $_user) {
            $_user_xt = str_split($_user);
            $_modes = '';
            $_nick = '';
            foreach ($_user_xt as $pos => $char) {
                if (isset($this->irc_nicknamePrefixes[$char])) {
                    $_modes .= $this->irc_nicknamePrefixes[$char];
                } else {
                    $_nick = implode(array_slice($_user_xt,$pos));
                    break;
                }
            }

            $User = $this->getUser($_nick);
            $ChannelUser = $this->getChannelUser($Line->args[1],$_nick);

            if ($User === false) { $this->irc_users[] = $User = new netIrc_User; }
            if ($ChannelUser === false) {
                $Channel->users[] = $ChannelUser = new netIrc_ChannelUser;
                $ChannelUser->user = $User;
                $User->channels[] = $Channel;
            }

            $User->nick = $_nick;
            $ChannelUser->modes = $_modes;
        }
    }

    protected function __handle366($Line) // NAMES end
    {
        $this->sendRaw('MODE '.$Line->args[0],1);
        $this->sendRaw('WHO '.$Line->args[0],1);
        $modes = str_split($this->irc_channel_modes[0]);
        foreach ($modes as $m) { $this->sendRaw('MODE '.$Line->args[0].' +'.$m,1); }
    }

    protected function __handle367($Line) // Banlist
    {
        $Channel = $this->getChannel($Line->args[0]);
        if (!isset($Channel->lists['b'])) { $Channel->lists['b'] = array(); }

        if (!isset($Channel->lists['b'][$Line->args[1]])) {
            $Channel->lists['b'][$Line->args[1]] = new stdClass;
            $Channel->lists['b'][$Line->args[1]]->by = $Line->args[2];
            $Channel->lists['b'][$Line->args[1]]->time = $Line->args[3];
        }

    }

    protected function __handle372($Line) // MOTD lines
    {
        $this->irc_motd[] = $Line->message;
    }

    protected function __handle375($Line) // MOTD start
    {
        $this->irc_motd = array();
    }

    protected function __handle376($Line) // MOTD end
    {
        $this->irc_logged_in = true;
        //$this->netSocket->setBlocking(0);

        $this->irc_users[] = $User = new netIrc_User;
        $User->nick = $this->irc_nickname;

        $this->sendRaw('WHO '.$User->nick,1);
    }

    protected function __handle386($Line) // Channel owners list
    {
        $ChannelUser = $this->getChannelUser($Line->args[0],$Line->args[1]);
        if ($ChannelUser === false) { return; }
        if (strpos($ChannelUser->modes,'q') === false) {
            $ChannelUser->modes .= 'q';
        }
    }

    protected function __handle388($Line) // Channel administrators list
    {
        $ChannelUser = $this->getChannelUser($Line->args[0],$Line->args[1]);
        if ($ChannelUser === false) { return; }
        if (strpos($ChannelUser->modes,'a') === false) {
            $ChannelUser->modes .= 'a';
        }
    }

    protected function __handle422($Line) // Motd missing
    {
        $this->__callHandler('376',$Line);
    }

    protected function __handle433($Line) // Nickname already in use
    {
        if (!$this->irc_logged_in) {
            $this->irc_nickname = $this->irc_nickname.'`';
            $this->sendNick($this->irc_nickname,0);
        }
    }

    protected function __handleCTCPREQ($Line)
    {
        if ($Line->message_xt[0] == 'VERSION') {
            $this->sendCtcpRep($Line->source->nick,'VERSION PHP netIrc by saymonz');
            $this->sendCtcpRep($Line->source->nick,'VERSION Find the code at https://bitbucket.org/saymonz/netirc/');
        }
        if ($Line->message_xt[0] == 'PING') {
            if (isset($Line->message_xt[1])) {
                $this->sendCtcpRep($Line->source->nick,'PING '.$Line->message_xt[1]);
            } else {
                $this->sendCtcpRep($Line->source->nick,'PING');
            }
        }
    }

    protected function __handleERROR($Line)
    {
        if ($this->irc_auto_reconnect) {
            if ($this->open()) {
                $this->listen();
            }
        } else {
            parent::close();
        }
        $this->irc_loop_break = true;
    }

    protected function __handleJOIN($Line)
    {
        if ($Line->source->nick == $this->irc_nickname) {
            $this->irc_channels[] = $Channel = new netIrc_Channel;
            $Channel->name = $Line->message;
        } else {
            $Channel = $this->getChannel($Line->message);
        }

        $User = $this->getUser($Line->source->nick);
        if ($User === false) {
            $this->irc_users[] = $User = new netIrc_User;
            $User->nick = $Line->source->nick;
            $User->ident = $Line->source->ident;
            $User->host = $Line->source->host;
            $User->mask = $Line->source->mask;
            $this->sendRaw('WHO '.$User->nick,1);
        }

        $User->channels[] = $Channel;

        $Channel->users[] = $ChannelUser = new netIrc_ChannelUser;
        $ChannelUser->user = $User;
    }

    protected function __handleKICK($Line)
    {
        if ($Line->args[0] == $this->irc_nickname) {
            $this->__deleteChannel($Line->target);
            $this->sendJoin($Line->target);
        } else {
            $this->__deleteChannelUser($Line->target,$Line->args[0]);
        }
    }

    protected function __handleMODE($Line)
    {
        $Line = clone $Line;
        if ($Line->target_ischannel) {
            $Channel = $this->getChannel($Line->target);
            $modes = str_split(array_shift($Line->args));
            foreach ($modes as $mode) {
                switch ($mode) {
                    case '+':
                        $m = true;
                    break;

                    case '-':
                        $m = false;
                    break;

                    default:
                        if (in_array($mode,$this->irc_nicknamePrefixes)) { // mode utilisateur préfixé
                            $ChannelUser = $this->getChannelUser($Line->target,array_shift($Line->args));
                            $ChannelUser->modes = $this->modeChange($ChannelUser->modes,$mode,$m);
                        } else {
                            foreach ($this->irc_channel_modes as $k => $v) {
                                if (strpos($v,$mode) !== false) { break; }
                            }

                            switch ($k) {
                                case 0:
                                    $m_target = array_shift($Line->args);
                                    if ($this->isMask($m_target)) {
                                        $m_target = strtolower($m_target);
                                        if ($m) {
                                            $Channel->lists[$mode][$m_target] = new stdClass;
                                            $Channel->lists[$mode][$m_target]->by = $Line->source->nick;
                                            $Channel->lists[$mode][$m_target]->time = time();
                                        } else {
                                            if (isset($Channel->lists[$mode][$m_target])) {
                                                unset($Channel->lists[$mode][$m_target]);
                                            }
                                        }
                                    } else {
                                        $ChannelUser = $this->getChannelUser($Line->target,$m_target);
                                        $ChannelUser->modes = $this->modeChange($ChannelUser->modes,$mode,$m);
                                    }
                                break;

                                case 1:
                                    $arg = array_shift($Line->args); // Cat. B, always shift a parameter

                                    if ($m) {
                                        $Channel->modes[$mode] = $arg;
                                    } else {
                                        unset($Channel->modes[$mode]);
                                    }
                                break;

                                case 2:
                                    if ($m) {
                                        $arg = array_shift($Line->args);
                                        $Channel->modes[$mode] = $arg;
                                    } else {
                                        unset($Channel->modes[$mode]);
                                    }
                                break;

                                case 3:
                                    if ($m) {
                                        $Channel->modes[$mode] = true;
                                    } else {
                                        unset($Channel->modes[$mode]);
                                    }
                                break;

                                default:
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
        if ($Line->source->nick == $this->irc_nickname) { $this->irc_nickname = $Line->message; } // Own nick change

        $User = $this->getUser($Line->source->nick);
        $User->nick = $Line->message;
    }

    protected function __handlePART($Line)
    {
        if ($Line->source->nick == $this->irc_nickname) {
            $this->__deleteChannel($Line->target);
        } else {
            $this->__deleteChannelUser($Line->target,$Line->source->nick);
        }
    }

    protected function __handlePING($Line)
    {
        $this->__send('PONG :'.$Line->message_xt[0],0);
    }

    protected function __handleQUIT($Line)
    {
        $this->__deleteUser($Line->source->nick);
    }

    protected function __handleTOPIC($Line)
    {
        $Channel = $this->getChannel($Line->target);
        $Channel->topic_by = $Line->source->nick;
        $Channel->topic = $Line->args[0];
        $Channel->topic_time = time();
    }

}
