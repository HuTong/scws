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
        $this->sphinx->SetFieldWeights(array(100,1));
        $this->sphinx->SetMatchMode($mode);
        $this->sphinx->setMaxQueryTime(10);
        if($options)
        {
            if((isset($options['filter']) && $options['filter']) && (isset($options['filtervals']) && $options['filtervals']))
            {
                $this->sphinx->SetFilter($options['filter'], $options['filtervals']);
            }
            if((isset($options['groupby']) && $options['groupby']) && (isset($options['groupsort']) && $options['groupsort']))
            {
                $this->sphinx->SetGroupBy($options['groupby'], SPH_GROUPBY_ATTR, $options['groupsort']);
            }
            if(isset($options['sortby']) && $options['sortby'])
            {
                $this->sphinx->SetSortMode(SPH_SORT_EXTENDED, $options['sortby']);
            }
            if(isset($options['sortexpr']) && $options['sortexpr'])
            {
                $this->sphinx->SetSortMode(SPH_SORT_EXPR, $options['sortexpr']);
            }
            if(isset($options['distinct']) && $options['distinct'])
            {
                $this->sphinx->SetGroupDistinct($options['distinct']);
            }
            if(isset($options['select']) && $options['select'])
            {
                $this->sphinx->SetSelect($options['select']);
            }
            $limit = isset($options['limit']) ? (int)$options['limit'] : 1000;
            if($limit)
            {
                $this->sphinx->SetLimits(0, $limit, ($limit>1000) ? $limit : 1000);
            }
            if(isset($options['ranker']) && $options['ranker'])
            {
                $this->sphinx->SetRankingMode($options['ranker']);
            }
            if(isset($options['resArray']) && $options['resArray'])
            {
                $this->sphinx->SetArrayResult(true);
            }
        }

        $res = $this->sphinx->query($q, $index);

        if($res == false)
        {
            return false;
        }

        return $res;
    }
}
