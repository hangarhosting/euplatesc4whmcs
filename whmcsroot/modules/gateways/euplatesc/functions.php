<?php
/**
 * Supporting functions for the EuPlatesc.ro gateway module for WHMCS
 * version 1.0.0, 2016.01.05
 * Copyright (c) EuPlatesc.ro (implementation manual)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *******************************************************************************
 * License also available at:      LICENSE
 * Changelog available at:         CHANGELOG
 *******************************************************************************
 */


/**
 * function used to calculate hmac
 * @return: string
 */
function hmacsha1($key,$data) {
   $blocksize = 64;
   $hashfunc  = 'md5';

   if(strlen($key) > $blocksize)
     $key = pack('H*', $hashfunc($key));
   $key  = str_pad($key, $blocksize, chr(0x00));
   $ipad = str_repeat(chr(0x36), $blocksize);
   $opad = str_repeat(chr(0x5c), $blocksize);

   $hmac = pack('H*', $hashfunc(($key ^ $opad) . pack('H*', $hashfunc(($key ^ $ipad) . $data))));
   return bin2hex($hmac);
}

/**
 * function used to build HMAC code for an array
 * @return: string
 */
function euplatesc_mac($data, $key = NULL)
{
  $str = NULL;

  foreach($data as $d)
  {
   	if($d === NULL || strlen($d) == 0)
  	  $str .= '-'; // valorile nule sunt inlocuite cu -
  	else
  	  $str .= strlen($d) . $d;
  }
  $key = pack('H*', $key);
  return hmacsha1($key, $str);
}

?>
