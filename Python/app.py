from flask import Flask, render_template, request, session, redirect, url_for
import requests
import json
from datetime import datetime
import re

app = Flask(__name__)
app.secret_key = 'your-secret-key'

def get_client_ip():
    headers = ['X-Forwarded-For', 'X-Real-IP', 'X-Client-IP']
    for header in headers:
        ip = request.headers.get(header)
        if ip:
            ips = ip.split(',')
            for ip in ips:
                ip = ip.strip()
                if is_valid_ip(ip):
                    return ip
    return request.remote_addr or '127.0.0.1'

def is_valid_ip(ip):
    pattern = r'^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$'
    return re.match(pattern, ip) is not None

def call_ip_api(input_ip=''):
    if not input_ip:
        input_ip = get_client_ip()
    
    try:
        response = requests.get(
            f'https://apikey.net/api/index?ip={input_ip}',
            timeout=10,
            headers={'User-Agent': 'IP Query Tool/1.0'}
        )
        if response.status_code == 200:
            return response.json()
    except Exception as e:
        return {'error': f'API请求失败: {str(e)}', 'input': input_ip}
    
    return {'error': 'API请求失败', 'input': input_ip}

@app.route('/', methods=['GET', 'POST'])
def index():
    client_ip = get_client_ip()
    client_data = call_ip_api(client_ip)
    
    query_result = None
    query_input = ''
    is_domain_query = False
    
    if request.method == 'POST':
        query_input = request.form.get('ip', '').strip()
        if query_input:
            return redirect(f'/?ip={query_input}')
    
    query_input = request.args.get('ip', '').strip()
    if query_input:
        query_result = call_ip_api(query_input)
        
        if 'resolvedIPs' in query_result:
            is_domain_query = True
        
        # 保存查询历史
        if 'query_history' not in session:
            session['query_history'] = []
        
        history = session['query_history']
        if query_input not in history:
            history.insert(0, query_input)
            if len(history) > 10:
                history.pop()
            session['query_history'] = history
    
    server_time = datetime.now().strftime('%Y年%m月%d日 %H:%M:%S')
    
    return render_template(
        'index.html',
        client_data=client_data,
        query_result=query_result,
        query_input=query_input,
        is_domain_query=is_domain_query,
        server_time=server_time,
        query_history=session.get('query_history', [])
    )

if __name__ == '__main__':
    app.run(debug=True)