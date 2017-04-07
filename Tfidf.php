<?php
namespace Hutong\Scws;
/**
 * cws 分词计算 TF、IDF 数值
 *
 * 计算方式根据官网案例提供
 */
class Tfidf
{
    public static function getTfIdf($word)
	{
		$result = array(
			'status' => false,
			'word'	 => $word,
			'message'=> '',
		);

		$word = trim(strip_tags($word));

		if(strlen($word) < 2)
		{
			$result['message'] = "请输入正确的词汇";
		}elseif(strlen($word) > 30){
			$result['message'] = "输入的词语太长了";
		}elseif(strpos($word, ' ') !== false){
			$result['message'] = "词汇不要包含空格";
		}elseif(preg_match('/[\x81-\xfe]/', $word) && preg_match('/[\x20-\x7f]{3}/', $word)){
			$result['message'] = "中英混合时字母最多只能出3个以下的连续字母";
		}else{
			$count = self::getCount($word);

			if($count < 0)
			{
				$result['message'] = "内部原因，计算失败！";
			}else{
				$res = self::getTfAndIdf($word, $count);

				$tf  = isset($res[0]) ? round($res[0],2) : 0.01;
				$idf = isset($res[1]) ? round($res[1],2) : 0.01;

				$result['message'] = '计算成功';
				$result['status']  = true;
				$result['tf']  = $tf;
				$result['idf'] = $idf;
			}
		}

		return $result;
	}

    private static function getTfAndIdf($word, $count)
	{
		if($count < 1000)
		{
			$count = 21000 - $count * 18;
		}

		$tf = log($count);
		$tf = pow($tf, 5) * log(strlen($word));
		$tf = log($tf);
		$idf = log(5000000000/$count);

		//if ($tf > 13) $idf *= 1.4;

		return array($tf, $idf);
	}

    private static function getCount($word)
	{
		$url  = "https://www.baidu.com/s?wd=".urlencode($word);
		$data = @file_get_contents($url);

		if(!$data)
		{
			return -1;
		}

		$pos0 = @strpos($data, "找到相关结果约", 2048) + @strlen("找到相关结果约");
		$pos1 = @strpos($data, "个", $pos0);

		$total = 0;
		if($pos0 > 0 && $pos1 > 0)
		{
			$str = substr($data, $pos0, $pos1 - $pos0);
			$total = (int) str_replace(",", "", $str);
		}

		return $total;
	}
}
