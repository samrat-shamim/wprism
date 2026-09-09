import sys, urllib.request, urllib.parse, http.cookiejar, json, re, pathlib
base, repo, evidence = sys.argv[1:]
evidence=pathlib.Path(evidence); evidence.mkdir(parents=True, exist_ok=True)
cookies=http.cookiejar.CookieJar()
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cookies))
def request(path, values=None, name='response'):
    data=None if values is None else urllib.parse.urlencode(values).encode()
    with client.open(base+path, data, timeout=30) as r:
        body=r.read().decode('utf-8')
        (evidence/(name+'.html')).write_text(body)
        if re.search(r'(?:PHP\s+)?(?:Fatal error|Warning|Parse error|Deprecated):',body):
            raise RuntimeError('native HTTP diagnostic in '+name)
        return body
request('/aio-wprism-login/', name='login-get')
request('/aio-wprism-login/', {'log':'admin','pwd':'admin','wp-submit':'Log In','testcookie':'1',
    'redirect_to':base+'/wp-admin/'}, 'login-post')
assert any(c.name.startswith('wordpress_logged_in_') for c in cookies), 'login did not establish native session'
body=request('/wp-admin/customize.php?aio_login_customizer=1',name='customizer-get')
match=re.search(r'var _wpCustomizeSettings\s*=\s*({.*?});\s*(?:\n|</script>)',body,re.S)
assert match, 'native customizer settings missing'
settings=json.loads(match.group(1))
settings['settings']={json.loads(k):json.loads(v) for k,v in re.findall(r'^s\[("(?:[^"\\]|\\.)*")\] = (\{.*\});$',body,re.M)}
assert 'aio_login_customizer' in settings['panels'], 'native AIO panel absent'
nonce=settings['nonce']['save']
customized=json.loads((pathlib.Path(repo)/'.tmp-aio-customizer.json').read_text())
assert all(k in settings['settings'] for k in customized), 'native settings registration incomplete'
reply=json.loads(request('/wp-admin/admin-ajax.php', {
    'action':'customize_save','nonce':nonce,'customize_theme':settings['theme']['stylesheet'],
    'customize_changeset_uuid':settings['changeset']['uuid'],'customize_changeset_status':'publish',
    'customized':json.dumps(customized),'aio_login_customizer':'1',
}, 'customizer-publish'))
assert reply['success'] is True, 'native Customizer publish refused: '+json.dumps(reply)
assert reply['data']['changeset_status']=='publish', 'changeset was not published'
body=request('/wp-admin/options-permalink.php',name='permalink-get')
nonce=re.search(r'name="_wpnonce" value="([^"]+)"',body)
assert nonce, 'native permalink form nonce missing'
request('/wp-admin/options-permalink.php', {'_wpnonce':nonce.group(1),'_wp_http_referer':'/wp-admin/options-permalink.php',
    'permalink_structure':'/%postname%/','selection':'/%postname%/','rwl_page':'aio-wprism-login',
    'rwl_redirect_field':'aio-wprism-missing','submit':'Save Changes'}, 'permalink-save')
print(json.dumps({'native_customizer_published':True,'settings':len(customized),'status':reply['data']['changeset_status']}))
