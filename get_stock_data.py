#!/usr/bin/env python
# -*- coding: utf-8 -*-

import sys
import json
from Ashare import *

def main():
    # 获取命令行参数
    if len(sys.argv) < 4:
        print(json.dumps({"error": "参数不足"}))
        sys.exit(1)
    
    code = sys.argv[1]
    frequency = sys.argv[2]
    count = int(sys.argv[3])
    end_date = sys.argv[4] if len(sys.argv) > 4 and sys.argv[4] else ''
    
    try:
        # 调用Ashare获取数据
        df = get_price(code, frequency=frequency, count=count, end_date=end_date)
        
        # 转换DataFrame为JSON
        df = df.reset_index()
        
        # 获取第一列名称(原索引列)
        index_col_name = df.columns[0]
        
        # 使用实际列名而不是假设为'index'
        df['time'] = df[index_col_name].astype(str)
        df = df.drop(index_col_name, axis=1)
        
        # 转为JSON格式输出
        result = df.to_dict(orient='records')
        print(json.dumps(result))
        
    except Exception as e:
        print(json.dumps({"error": str(e)}))
        sys.exit(1)

if __name__ == "__main__":
    main()
