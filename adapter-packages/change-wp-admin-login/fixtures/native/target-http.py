import sys, urllib.request, urllib.parse, urllib.error, http.cookiejar, json, re, pathlib
base, source, evidence = sys.argv[1:]
evidence=pathlib.Path(evidence); evidence.mkdir(parents=True,exist_ok=True)
cookies=http.cookiejar.CookieJar()
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
def request(path, values=None, name='response'):
    data=None if values is None else urllib.parse.urlencode(values).encode()
    try: r=client.open(base+path,data,timeout=30)
    except urllib.error.HTTPError as error: r=error
    with r:
        body=r.read().decode('utf-8')
        (evidence/(name+'.html')).write_text(body)
        assert not re.search(r'(?:PHP\s+)?(?:Fatal error|Warning|Parse error|Deprecated):',body),'native diagnostic'
        return r.status,r.url,body
status,url,body=request('/aio-wprism-login/',name='login')
assert status==200 and 'AIO secure entrance' in body and 'AIO বাংলা brand' in body,(status,'custom login design missing')
assert 'outline: 2px solid #123456' in body and '410px' in body,'native CSS values missing'
assert source not in body, 'source URL leaked into target login render'
status,url,body=request('/wp-json/wp/v2/users',name='anonymous-users')
assert status==403 and json.loads(body)['code']=='rest_forbidden','native anonymous user enumeration was not blocked'
status,url,body=request('/aio-wprism-login/',{'log':'admin','pwd':'admin','wp-submit':'Log In','testcookie':'1','redirect_to':base+'/wp-admin/'},'authenticated-login')
assert status==200 and url==base+'/aio-login-destination/' and 'aio-public-control' in body,(status,url,'native login redirect failed')
assert any(c.name.startswith('wordpress_logged_in_') for c in cookies),'native session absent'
print(json.dumps({'custom_login_render':True,'css_media_url_rebound':True,'native_password_login_and_redirect':True}))
