<?php
namespace Hutong\Scws;
/**
 * scws 分词查询
 */
class Query
{
    private $scws;

    /**
     * 初始化   scws
     *
     * @param  string $dict
     * @param  string $rule
     * @param  string $charset
     * @author hutong
     * @date   2017-04-07T16:53:11+080
     */
    public function __construct($dict, $rule = '', $charset = 'utf8')
    {
        if(!extension_loaded('scws'))
        {
            throw new \Exception('scws 扩展未加载');
        }

        $fpath = ini_get('scws.default.fpath');

        $this->scws = scws_new();
        $this->scws->set_charset($charset);

        if(empty($dict))
        {
            throw new \Exception('系统词典地址不能为空');
        }elseif(is_file($fpath.'/'.$dict)){
            $this->scws->set_dict(ini_get('scws.default.fpath').'/'.$dict);
        }else{
            throw new \Exception('系统词典地址不可用');
        }

        if($rule)
        {
            if(is_file($rule))
            {
                $this->scws->set_rule($rule);
            }elseif(is_file($fpath.'/'.$rule)){
                $this->scws->set_rule($fpath.'/'.$rule);
            }
        }
    }

    /**
     * 添加分词所用的词典，新加入的优先查找
     *
     * @param  string $dict
     * @author hutong
     * @date   2017-04-07T16:52:12+080
     */
    public function addDict($dict)
    {
        $exp = explode('.', $dict);
        $ext = end($exp);

        switch (strtolower($ext))
        {
            case 'txt':
                $mode = 'SCWS_XDICT_TXT';
                break;
            case 'xdb':
                $mode = 'SCWS_XDICT_XDB';
                break;
            default:
                $mode = 'SCWS_XDICT_MEM';
                break;
        }
        if(is_file($dict))
        {
            $this->scws->add_dict($dict, $mode);
        }
    }

    /**
     * 设定分词所用的新词识别规则集(用于人名、地名、数字时间年代等识别)
     *
     * @param  string $rule
     * @author hutong
     * @date   2017-04-07T16:49:11+080
     */
    public function setRule($rule)
    {
        if(is_file($rule))
        {
            $this->scws->set_rule($rule);
        }
    }

    private function sendText($text, $ignore = true, $multi = false, $duality = false)
    {
        $this->scws->set_ignore($ignore);
        $this->scws->set_multi($multi);
        $this->scws->set_duality($duality);
        $this->scws->send_text($text);
    }

    /**
     * 根据 send_text 设定的文本内容，返回一系列切好的词汇
     *
     * @param  string $text
     * @param  boolean $ignore
     * @param  boolean $multi
     * @param  boolean $duality
     * @return array
     * @author hutong
     * @date   2017-04-07T16:48:36+080
     */
    public function getResult($text, $ignore = true, $multi = false, $duality = false)
    {
        $this->sendText($text, $ignore, $multi, $duality);

        $words = array();
        while ($result = $this->scws->get_result())
        {
            foreach ($result as $tmp)
            {
                $words[] = $tmp['word'];
            }
        }

        return $words;
    }

    /**
     * 根据 send_text 设定的文本内容，返回系统计算出来的最关键词汇列表
     *
     * @param  string $text
     * @param  integer $limit
     * @param  string $attr 多个属性用 , 号连接
     * @param  boolean $ignore
     * @param  boolean $multi
     * @param  boolean $duality
     * @return array
     * @author hutong
     * @date   2017-04-07T16:47:44+080
     */
    public function getTops($text, $limit = 10, $attr = '', $ignore = true, $multi = false, $duality = false)
    {
        $this->sendText($text, $ignore, $multi, $duality);

        $result = $this->scws->get_tops($limit, $attr);

        $words = array();
        foreach ($result as $tmp)
        {
            $words[] = $tmp['word'];
        }

        return $words;
    }

    /**
     * 根据 send_text 设定的文本内容，返回系统中词性符合要求的关键词汇
     *
     * @param  string $text
     * @param  string $attr 多个属性用 , 号连接
     * @param  boolean $ignore
     * @param  boolean $multi
     * @param  boolean $duality
     * @return array
     * @author hutong
     * @date   2017-04-07T16:47:01+080
     */
    public function getWords($text, $attr, $ignore = true, $multi = false, $duality = false)
    {
        $this->sendText($text, $ignore, $multi, $duality);

        $result = $this->scws->get_words($attr);

        $words = array();
        foreach ($result as $tmp)
        {
            $words[] = $tmp['word'];
        }

        return $words;
    }

    public function close()
    {
        $this->scws->close();
    }
}
