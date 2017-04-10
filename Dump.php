<?php
/**
 * 将词典 xdb文件导出成 txt文件
 *
 * eg:
 * 		Dump.php /usr/local/scws/etc/dict.utf8.xdb ./a.txt
 */

ini_set('memory_limit', '1024M');
set_time_limit(0);

$dict = isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : '';
$txt = isset($_SERVER['argv'][2]) ? $_SERVER['argv'][2] : '';

if(empty($dict))
{
	echo "is empty dict path \n";exit;
}
if(empty($txt))
{
	echo "is empty txt path \n";exit;
}

(new Dump($dict, $txt))->run();

class Dump
{
	private $dict;
	private $txt;
	public function __construct($dict, $txt)
	{
		if(!is_file($dict))
		{
			echo "Usage: $dict <xdb file> [output file]\n";
			exit;
		}
		$this->dict = $dict;

		$this->txt = $txt;
	}

	public function run()
	{
		$output = $this->txt ? $this->txt : 'php://stdout';
		if (!($fd = @fopen($output, 'w')))
		{
			echo "ERROR: can not open the output file: {$output}\n";
			exit;
		}

		$xdb = new XTreeDB;
		if (!$xdb->Open($this->dict))
		{
			fclose($fd);
			echo "ERROR: input file {$this->dict} maybe not a valid XDB file.\n";
			exit;
		}

		$line = "# WORD\tTF\tIDF\tATTR\n";
		fwrite($fd, $line);
		$xdb->Reset();
		while ($tmp = $xdb->Next())
		{
			if (strlen($tmp['value']) != 12)
			{
				continue;
			}
			$word = $tmp['key'];
			$data = unpack("ftf/fidf/Cflag/a3attr", $tmp['value']);
			if (!($data['flag'] & 0x01))
			{
				continue;
			}

			$tf = isset($data['tf']) ? $data['tf'] : 0.01;
			$idf = isset($data['idf']) ? $data['idf'] : 0.01;
			$attr = isset($data['attr']) ? $data['attr'] : '@';

			$line = sprintf("%s\t%.2f\t%.2f\t%.2s\n", $word, $tf, $idf, $data['attr']);
			fwrite($fd, $line);
		}
		fclose($fd);
		$xdb->Close();
	}
}

class XTreeDB
{
	// Public var
	var $fd = false;
	var $mode = 'r';
	var $hash_base = 0xf422f;
	var $hash_prime = 2047;
	var $version = 34;
	var $fsize = 0;
	var $float_check = 3.14;
	var $tag_name = 'XDB';
	var $max_klen = 0xf0;

	// Private
	var $trave_stack = array();
	var $trave_index = -1;

	// Debug test
	var $_io_times = 0;

	// Constructor Function
	function __construct($base = 0, $prime = 0)
	{
		if (0 != $base)
        {
            $this->hash_base = $base;
        }
		if (0 != $prime)
        {
            $this->hash_prime = $prime;
        }
	}

	// Open the database: read | write
	function Open($fpath, $mode = 'r')
	{
		// open the file
		$this->Close();

		$newdb = false;
		if ($mode == 'w')
		{
			// write & read only
			if (!($fd = @fopen($fpath, 'rb+')))
			{
				if (!($fd = @fopen($fpath, 'wb+')))
				{
					trigger_error("XDB::Open(" . basename($fpath) . ",w) failed.", E_USER_WARNING);
					return false;
				}
				// create the header
				$this->_write_header($fd);

				// 32 = header, 8 = Pointer
				$this->fsize = 32 + 8 * $this->hash_prime;
				$newdb = true;
			}
		}
		else
		{
			// read only
			if (!($fd = @fopen($fpath, 'rb')))
			{
				trigger_error("XDB::Open(" . basename($fpath) . ",r) failed.", E_USER_WARNING);
				return false;
			}
		}

		// check the header
		if (!$newdb && !$this->_check_header($fd))
		{
			trigger_error("XDB::Open(" . basename($fpath) . "), invalid xdb format.", E_USER_WARNING);
			fclose($fd);
			return false;
		}

		// set the variable
		$this->fd = $fd;
		$this->mode = $mode;
		$this->Reset();

		// lock the file description until close
		if ($mode == 'w')
        {
            flock($this->fd, LOCK_EX);
        }

		return true;
	}

	// Insert Or Update the value
	function Put($key, $value)
	{
		// check the file description
		if (!$this->fd || $this->mode != 'w')
		{
			trigger_error("XDB::Put(), null db handler or readonly.", E_USER_WARNING);
			return false;
		}

		// check the length
		$klen = strlen($key);
		$vlen = strlen($value);
		if (!$klen || $klen > $this->max_klen)
		{
            return false;
        }

		// try to find the old data
		$rec = $this->_get_record($key);
		if (isset($rec['vlen']) && ($vlen <= $rec['vlen']))
		{
			// update the old value & length
			if ($vlen > 0)
			{
				fseek($this->fd, $rec['voff'], SEEK_SET);
				fwrite($this->fd, $value, $vlen);
			}

			if ($vlen < $rec['vlen'])
			{
				$newlen = $rec['len'] + $vlen - $rec['vlen'];
				$newbuf = pack('I', $newlen);
				fseek($this->fd, $rec['poff'] + 4, SEEK_SET);
				fwrite($this->fd, $newbuf, 4);
			}
			return true;
		}

		// 构造数据
		$new = array('loff' => 0, 'llen' => 0, 'roff' => 0, 'rlen' => 0);
		if (isset($rec['vlen']))
		{
			$new['loff'] = $rec['loff'];
			$new['llen'] = $rec['llen'];
			$new['roff'] = $rec['roff'];
			$new['rlen'] = $rec['rlen'];
		}
		$buf  = pack('IIIIC', $new['loff'], $new['llen'], $new['roff'], $new['rlen'], $klen);
		$buf .= $key . $value;
		$len  = $klen + $vlen + 17;

		$off  = $this->fsize;
		fseek($this->fd, $off, SEEK_SET);
		fwrite($this->fd, $buf, $len);
		$this->fsize += $len;

		$pbuf = pack('II', $off, $len);
		fseek($this->fd, $rec['poff'], SEEK_SET);
		fwrite($this->fd, $pbuf, 8);
		return true;
	}

	// Read the value by key
	function Get($key, $debug = false)
	{
		// check the file description
		if (!$this->fd)
		{
			trigger_error("XDB::Get(), null db handler.", E_USER_WARNING);
			return false;
		}

		$klen = strlen($key);
		if ($klen == 0 || $klen > $this->max_klen)
	    {
            return false;
        }

		// get the data?
		$rec = $this->_get_record($key);
		if ($debug)
		{
            return $rec;
        }

		if (!isset($rec['vlen']) || $rec['vlen'] == 0)
		{
            return false;
        }

		return $rec['value'];
	}

	// Read the each key & value
	// return array(key => xxx, value => xxx)
	function Next()
	{
		// check the file description
		if (!$this->fd)
		{
			trigger_error("XDB::Next(), null db handler.", E_USER_WARNING);
			return false;
		}

		// Traversal the all tree
		if (!($ptr = array_pop($this->trave_stack)))
		{
			do
			{
				$this->trave_index++;
				if ($this->trave_index >= $this->hash_prime)
				{
                    break;
                }

				$poff = $this->trave_index * 8 + 32;
				fseek($this->fd, $poff, SEEK_SET);
				$buf = fread($this->fd, 8);
				if (strlen($buf) != 8)
				{
					$ptr = false;
					break;
				}

				$ptr = unpack('Ioff/Ilen', $buf);
			}
			while ($ptr['len'] == 0);
		}

		// end the all records?
		if (!$ptr || $ptr['len'] == 0)
		{
            return false;
        }

		// read the record
		$rec = $this->_tree_get_record($ptr['off'], $ptr['len']);

		// push the left & right
		if ($rec['llen'] != 0)
		{
			$left = array('off' => $rec['loff'], 'len' => $rec['llen']);
			array_push($this->trave_stack, $left);
		}
		if ($rec['rlen'] != 0)
		{
			$right = array('off' => $rec['roff'], 'len' => $rec['rlen']);
			array_push($this->trave_stack, $right);
		}

		// return value
		return $rec;
	}

	// Traversal every tree... & debug to test
	function Draw($i = -1)
	{
		if ($i < 0 || $i >= $this->hash_prime)
		{
			$i = 0;
			$j = $this->hash_prime;
		}
		else
		{
			$j = $i + 1;
		}

		echo "Draw the XDB data [$i ~ $j]. (" . trim($this->Version()) . ")\n\n";
		while ($i < $j)
		{
			$poff = $i * 8 + 32;
			fseek($this->fd, $poff, SEEK_SET);
			$buf = fread($this->fd, 8);
			if (strlen($buf) != 8)
            {
                break;
            }
			$ptr = unpack('Ioff/Ilen', $buf);

			$this->_cur_depth = 0;
			$this->_node_num = 0;
			$this->_draw_node($ptr['off'], $ptr['len']);
			echo "-------------------------------------------\n";
			echo "Tree(xdb) [$i] max_depth: {$this->_cur_depth} nodes_num: {$this->_node_num}\n";
			$i++;
		}
	}

	// Reset the inner pointer
	function Reset()
	{
		$this->trave_stack = array();
		$this->trave_index = -1;
	}

	// Show the version
	function Version()
	{
		$ver = (is_null($this) ? $this->version : $this->version);
		$str = sprintf("%s/%d.%d", $this->tag_name, ($ver >> 5), ($ver & 0x1f));
		if (!is_null($this))
        {
            $str .= " <base={$this->hash_base}, prime={$this->hash_prime}>";
        }
		return $str;
	}

	// Close the DB
	function Close()
	{
		if (!$this->fd)
        {
            return;
        }

		if ($this->mode == 'w')
		{
			$buf = pack('I', $this->fsize);
			fseek($this->fd, 12, SEEK_SET);
			fwrite($this->fd, $buf, 4);
			flock($this->fd, LOCK_UN);
		}
		fclose($this->fd);
		$this->fd = false;
	}

	// Optimize the tree
	function Optimize($i = -1)
	{
		// check the file description
		if (!$this->fd || $this->mode != 'w')
		{
			trigger_error("XDB::Optimize(), null db handler or readonly.", E_USER_WARNING);
			return false;
		}

		// get the index zone:
		if ($i < 0 || $i >= $this->hash_prime)
		{
			$i = 0;
			$j = $this->hash_prime;
		}
		else
		{
			$j = $i + 1;
		}

		// optimize every index
		while ($i < $j)
		{
			$this->_optimize_index($i);
			$i++;
		}
	}

	// optimize a node
	function _optimize_index($index)
	{
		static $cmp = false;
		$poff = $index * 8 + 32;

		// save all nodes into array()
		$this->_sync_nodes = array();
		$this->_load_tree_nodes($poff);

		$count = count($this->_sync_nodes);
		if ($count < 3)
        {
            return;
        }

		// sync the nodes, sort by key first
		if ($cmp == false)
        {
            $cmp = create_function('$a,$b', 'return strcmp($a[key],$b[key]);');
        }
		usort($this->_sync_nodes, $cmp);
		$this->_reset_tree_nodes($poff, 0, $count - 1);
		unset($this->_sync_nodes);
	}

	// load tree nodes
	function _load_tree_nodes($poff)
	{
		fseek($this->fd, $poff, SEEK_SET);
		$buf = fread($this->fd, 8);
		if (strlen($buf) != 8)
        {
            return;
        }

		$tmp = unpack('Ioff/Ilen', $buf);
		if ($tmp['len'] == 0)
        {
            return;
        }
		fseek($this->fd, $tmp['off'], SEEK_SET);

		$rlen = $this->max_klen + 17;
		if ($rlen > $tmp['len'])
        {
            $rlen = $tmp['len'];
        }
		$buf = fread($this->fd, $rlen);

		$rec = unpack('Iloff/Illen/Iroff/Irlen/Cklen', substr($buf, 0, 17));
		$rec['off'] = $tmp['off'];
		$rec['len'] = $tmp['len'];
		$rec['key'] = substr($buf, 17, $rec['klen']);
		$this->_sync_nodes[] = $rec;
		unset($buf);

		// left
		if ($rec['llen'] != 0)
        {
            $this->_load_tree_nodes($tmp['off']);
        }
		// right
		if ($rec['rlen'] != 0)
        {
            $this->_load_tree_nodes($tmp['off'] + 8);
        }
	}

	// sync the tree
	function _reset_tree_nodes($poff, $low, $high)
	{
		if ($low <= $high)
		{
			$mid = ($low+$high)>>1;
			$node = $this->_sync_nodes[$mid];
			$buf = pack('II', $node['off'], $node['len']);

			// left
			$this->_reset_tree_nodes($node['off'], $low, $mid - 1);
			// right
			$this->_reset_tree_nodes($node['off'] + 8, $mid + 1, $high);
		}
		else
		{
			$buf = pack('II', 0, 0);
		}

		fseek($this->fd, $poff, SEEK_SET);
		fwrite($this->fd, $buf, 8);
	}

	// Privated Function
	function _get_index($key)
	{
		$l = strlen($key);
		$h = $this->hash_base;
		while ($l--)
		{
			$h += ($h << 5);
			$h ^= ord($key[$l]);
			$h &= 0x7fffffff;
		}
		return ($h % $this->hash_prime);
	}

	// draw the tree nodes by off & len
	function _draw_node($off, $len, $rl = 'T', $icon = '', $depth = 0)
	{
		if ($rl == 'T')
        {
            echo '(Ｔ) ';
        } else {
			echo $icon;
			if ($rl == 'L')
			{
				$icon .= ' ┃';
				echo ' ┟(Ｌ) ';
			}
			else
			{
				$icon .= ' 　';
				echo ' └(Ｒ) ';
			}
		}
		if ($len == 0)
		{
			echo "<NULL>\n";
			return;
		}

		$rec = $this->_tree_get_record($off, $len);
		echo "{$rec[key]} (vlen={$rec[vlen]}, voff={$rec[voff]})\n";
		unset($rec['key'], $rec['value']);

		// debug used
		$this->_node_num++;
		$depth++;
		if ($depth >= $this->_cur_depth)
		{
            $this->_cur_depth = $depth;
        }

		// Left node & Right Node
		$this->_draw_node($rec['loff'], $rec['llen'], 'L', $icon, $depth);
		$this->_draw_node($rec['roff'], $rec['rlen'], 'R', $icon, $depth);
	}

	// Check XDB Header
	function _check_header($fd)
	{
		fseek($fd, 0, SEEK_SET);
		$buf = fread($fd, 32);
		if (strlen($buf) !== 32)
        {
            return false;
        }
		$hdr = unpack('a3tag/Cver/Ibase/Iprime/Ifsize/fcheck/a12reversed', $buf);
		if ($hdr['tag'] != $this->tag_name)
        {
            return false;
        }

		// check the fsize
		$fstat = fstat($fd);
		if ($fstat['size'] != $hdr['fsize'])
		{
            return false;
        }

		// check float?

		$this->hash_base = $hdr['base'];
		$this->hash_prime = $hdr['prime'];
		$this->version = $hdr['ver'];
		$this->fsize = $hdr['fsize'];
		return true;
	}

	// Write XDB Header
	function _write_header($fd)
	{
		$buf = pack('a3CiiIfa12', $this->tag_name, $this->version, $this->hash_base, $this->hash_prime, 0, $this->float_check, '');

		fseek($fd, 0, SEEK_SET);
		fwrite($fd, $buf, 32);
	}

	// get the record by first key
	function _get_record($key)
	{
		$this->_io_times = 1;
		$index = ($this->hash_prime > 1 ? $this->_get_index($key) : 0);
		$poff = $index * 8 + 32;
		fseek($this->fd, $poff, SEEK_SET);
		$buf = fread($this->fd, 8);

		if (strlen($buf) == 8)
        {
            $tmp = unpack('Ioff/Ilen', $buf);
        } else {
            $tmp = array('off' => 0, 'len' => 0);
        }
		return $this->_tree_get_record($tmp['off'], $tmp['len'], $poff, $key);
	}

	// get the record by tree
	function _tree_get_record($off, $len, $poff = 0, $key = '')
	{
		if ($len == 0)
		{
            return (array('poff' => $poff));
        }
		$this->_io_times++;

		// get the data & compare the key data
		fseek($this->fd, $off, SEEK_SET);
		$rlen = $this->max_klen + 17;
		if ($rlen > $len)
        {
            $rlen = $len;
        }
		$buf = fread($this->fd, $rlen);
		$rec = unpack('Iloff/Illen/Iroff/Irlen/Cklen', substr($buf, 0, 17));
		$fkey = substr($buf, 17, $rec['klen']);
		$cmp = ($key ? strcmp($key, $fkey) : 0);
		if ($cmp > 0)
		{
			// --> right
			unset($buf);
			return $this->_tree_get_record($rec['roff'], $rec['rlen'], $off + 8, $key);
		}
		else if ($cmp < 0)
		{
			// <-- left
			unset($buf);
			return $this->_tree_get_record($rec['loff'], $rec['llen'], $off, $key);
		} else {
			// found!!
			$rec['poff'] = $poff;
			$rec['off'] = $off;
			$rec['len'] = $len;
			$rec['voff'] = $off + 17 + $rec['klen'];
			$rec['vlen'] = $len - 17 - $rec['klen'];
			$rec['key'] = $fkey;

			fseek($this->fd, $rec['voff'], SEEK_SET);
			$rec['value'] = fread($this->fd, $rec['vlen']);
			return $rec;
		}
	}
}
