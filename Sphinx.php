<?php
namespace Hutong\Scws;
/**
 * sphinx 搜索
 *
 * 中文分词的时候结合scws分词搜索
 */
class Sphinx
{
    private $sphinx;
    private $host;
    private $port;

    public function __construct($host = 'localhost', $port = 9312, $keepActive = false)
    {
        if(!extension_loaded('sphinx'))
        {
            throw new \Exception('sphinx 扩展未加载');
        }
        $sphinx = new \SphinxClient;
        $sphinx->setServer($host, $port);
        if($keepActive)
        {
            if(!$sphinx->open())
            {
                throw new \Exception('sphinx 长连接开启失败');
            }
        }

        $this->sphinx = $sphinx;
    }

    public function Query($q, $index, $mode = SPH_MATCH_EXTENDED2, $options = array())
    {
        $this->sphinx->setFieldWeights(array(100,1));
        $this->sphinx->setMatchMode($mode);
        $this->sphinx->setMaxQueryTime(10);
        if($options)
        {
            if(isset($options['filters']) && $options['filters'])
            {
                foreach ($options['filters'] as $key => $value)
                {
                    $this->sphinx->setFilter($key, $value[0], isset($value[1]) ? $value[1]:false);
                }
            }
            if(isset($options['filterRanges']) && $options['filterRanges'])
            {
                foreach ($options['filterRanges'] as $key => $value)
                {
                    $this->sphinx->setFilterRange($key, $value[0], $value[1], isset($value[2]) ? $value[2]:false);
                }
            }
            if(isset($options['filterFloatRanges']) && $options['filterFloatRanges'])
            {
                foreach ($options['filterFloatRanges'] as $key => $value)
                {
                    $this->sphinx->setFilterFloatRange($key, $value[0], $value[1], isset($value[2]) ? $value[2]:false);
                }
            }
            if(isset($options['groupby']) && $options['groupby'])
            {
                $this->sphinx->setGroupBy($options['groupby'][0], isset($options['groupby'][2]) ? $options['groupby'][2]:SPH_GROUPBY_ATTR, $options['groupby'][1]);
            }
            if(isset($options['sortby']) && $options['sortby'])
            {
                $this->sphinx->setSortMode(SPH_SORT_EXTENDED, $options['sortby']);
            }
            if(isset($options['sortexpr']) && $options['sortexpr'])
            {
                $this->sphinx->setSortMode(SPH_SORT_EXPR, $options['sortexpr']);
            }
            if(isset($options['distinct']) && $options['distinct'])
            {
                $this->sphinx->setGroupDistinct($options['distinct']);
            }
            if(isset($options['select']) && $options['select'])
            {
                $this->sphinx->setSelect($options['select']);
            }
            $limit = isset($options['limit']) ? (int)$options['limit'] : 1000;
            if($limit)
            {
                $this->sphinx->setLimits(0, $limit, ($limit>1000) ? $limit : 1000);
            }
            if(isset($options['ranker']) && $options['ranker'])
            {
                $this->sphinx->SetRankingMode($options['ranker']);
            }
            if(isset($options['resArray']) && $options['resArray'])
            {
                $this->sphinx->setArrayResult(true);
            }
        }

        $res = $this->sphinx->query($q, $index);

        $this->sphinx->resetFilters();
        $this->sphinx->resetGroupBy();

        if($res == false)
        {
            return false;
        }

        return $res;
    }

    public function buildExcerpts($docs, $index, $words, $opts = array())
    {
        $info = $this->sphinx->BuildExcerpts($docs, $index, $words, $opts);

        return $info;
    }

    public function close()
    {
        return $this->sphinx->close();
    }
}
