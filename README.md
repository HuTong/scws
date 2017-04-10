# scws
scws 中文分词

安装scws
sudo wget http://www.xunsearch.com/scws/down/scws-1.2.3.tar.bz2
sudo tar xvjf scws-1.2.3.tar.bz2
sudo  ./configure --prefix=/usr/local/scws  --enable-namerule
sudo ./configure --with-scws=/usr/local/scws --with-php-config=/usr/local/php/bin/php-config

php.ini 配置
[scws]
extension=scws.so
scws.default.charset = "utf8"
scws.default.fpath= "/usr/local/scws/etc"

Query.php
scws 接口文件

Tfidf.php
新词生词对应的TF/IDF计算器

Dump.php
将xdb文件导出成可视的纯文本文件

/bin/scws-gen-dict
该命令脚本可以将txt文件生成xdb词典文件
