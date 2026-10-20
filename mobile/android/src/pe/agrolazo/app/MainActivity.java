package pe.agrolazo.app;

import android.Manifest;
import android.app.*;
import android.content.*;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.location.*;
import android.net.Uri;
import android.net.ConnectivityManager;
import android.net.Network;
import android.net.http.SslError;
import android.os.*;
import android.view.*;
import android.view.autofill.AutofillManager;
import android.webkit.*;
import android.widget.*;
import org.json.JSONObject;
import java.io.*;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.concurrent.Executors;

/** A single-origin Android client for the shared AgroLazo application. */
public final class MainActivity extends Activity {
    private WebView web;
    private LinearLayout root, connectionPanel;
    private ProgressBar progress;
    private String server;
    private ValueCallback<Uri[]> fileCallback;
    private GeolocationPermissions.Callback geoCallback;
    private String geoOrigin;
    private String pendingDownload;
    private boolean locationPending;
    private boolean pageFailed;
    private boolean loginAutofillPending;
    private JSONObject latestAlerts;
    private ConnectivityManager connectivity;
    private ConnectivityManager.NetworkCallback networkCallback;
    private final Handler handler = new Handler(Looper.getMainLooper());
    private LocationListener locationListener;
    private LocationManager locations;
    private final java.util.concurrent.ExecutorService downloads = Executors.newSingleThreadExecutor();
    private static final int PICK_FILE = 41, SAVE_FILE = 42, LOCATION_PERMISSION = 43, NOTIFICATION_PERMISSION = 44;

    @Override public void onCreate(Bundle state) {
        super.onCreate(state);
        server = getString(R.string.default_server);
        getPreferences(MODE_PRIVATE).edit().remove("server").apply();
        root = new LinearLayout(this); root.setOrientation(LinearLayout.VERTICAL); root.setBackgroundColor(Color.rgb(21,63,45));
        if (Build.VERSION.SDK_INT >= 30) root.setOnApplyWindowInsetsListener((v, insets) -> {
            android.graphics.Insets bars = insets.getInsets(WindowInsets.Type.systemBars() | WindowInsets.Type.ime());
            v.setPadding(bars.left, bars.top, bars.right, bars.bottom); return insets;
        });
        progress = new ProgressBar(this,null,android.R.attr.progressBarStyleHorizontal); root.addView(progress,new LinearLayout.LayoutParams(-1,dp(3)));
        web = new WebView(this); web.setBackgroundColor(Color.rgb(246,247,241));
        web.setId(R.id.agrolazo_web); web.setImportantForAutofill(View.IMPORTANT_FOR_AUTOFILL_YES);
        WebSettings ws = web.getSettings(); ws.setJavaScriptEnabled(true); ws.setDomStorageEnabled(true); ws.setAllowFileAccess(false); ws.setAllowContentAccess(true);
        ws.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW); ws.setGeolocationEnabled(true); ws.setSupportMultipleWindows(false); ws.setJavaScriptCanOpenWindowsAutomatically(false);
        ws.setSafeBrowsingEnabled(true); ws.setUserAgentString(ws.getUserAgentString()+" AgroLazoAndroid/"+appVersion());
        CookieManager.getInstance().setAcceptCookie(true); CookieManager.getInstance().setAcceptThirdPartyCookies(web,false);
        web.addJavascriptInterface(new LocationBridge(),"AgroLazoLocation");
        web.addJavascriptInterface(new AlertsBridge(),"AgroLazoAlerts");
        web.addJavascriptInterface(new AutofillBridge(),"AgroLazoAutofill");
        Alerts.channel(this);
        web.setWebViewClient(new WebViewClient() {
            @Override public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                if (trusted(request.getUrl().toString())) return false;
                Toast.makeText(MainActivity.this,"Este enlace no pertenece a AgroLazo. Continúa desde la aplicación.",Toast.LENGTH_LONG).show(); return true;
            }
            @Override public WebResourceResponse shouldInterceptRequest(WebView view, WebResourceRequest request) {
                Uri uri = request.getUrl(); String scheme = uri.getScheme();
                if (!"http".equals(scheme) && !"https".equals(scheme)) return new WebResourceResponse("text/plain","UTF-8",new ByteArrayInputStream(new byte[0]));
                if (request.isForMainFrame() && !trusted(uri.toString())) return new WebResourceResponse("text/plain","UTF-8",new ByteArrayInputStream(new byte[0]));
                return null;
            }
            @Override public void onPageStarted(WebView view,String url,android.graphics.Bitmap icon) { pageFailed=false; progress.setVisibility(View.VISIBLE); }
            @Override public void onPageFinished(WebView view,String url) {
                progress.setVisibility(View.GONE); CookieManager.getInstance().flush();
                if (!pageFailed && trusted(url)) { connectionPanel.setVisibility(View.GONE); web.setVisibility(View.VISIBLE); installLocationAdapter(); }
                if (!pageFailed && trusted(url) && "/login".equals(Uri.parse(url).getPath())) {
                    loginAutofillPending=true;
                    web.clearHistory();
                    boolean rememberAccess=getPreferences(MODE_PRIVATE).getBoolean("remember_credentials",true);
                    web.evaluateJavascript("(function(){const b=document.querySelector('[data-native-autofill]');if(!b||b.dataset.ready)return;b.dataset.ready='1';b.hidden=false;const h=document.querySelector('[data-native-autofill-help]');if(h)h.hidden=false;b.addEventListener('click',function(){document.getElementById('email').focus();AgroLazoAutofill.request();});const r=document.querySelector('[data-native-remember]');if(r){r.checked="+rememberAccess+";r.addEventListener('change',function(){AgroLazoAutofill.remember(r.checked);});r.form.addEventListener('submit',function(){AgroLazoAutofill.remember(r.checked);});}})();",null);
                    AutofillManager manager=getSystemService(AutofillManager.class);
                    if(manager==null || !manager.isEnabled())web.evaluateJavascript("(function(){const b=document.querySelector('[data-native-autofill]');const h=document.querySelector('[data-native-autofill-help]');if(b)b.textContent='Activar autocompletado';if(h)h.textContent='Actívalo para que Android pueda guardar tu correo y contraseña. Después vuelve aquí e inicia sesión.';})();",null);
                }
                if (!pageFailed && trusted(url)) web.evaluateJavascript("!!document.querySelector('[data-platform-account]')", result -> {
                    String path=Uri.parse(url).getPath();
                    if (url.equals(web.getUrl()) && "true".equals(result) && loginAutofillPending) {
                        loginAutofillPending=false;
                        AutofillManager autofill=getSystemService(AutofillManager.class);
                        if(autofill!=null) {
                            if(getPreferences(MODE_PRIVATE).getBoolean("remember_credentials",true))autofill.commit();
                            else autofill.cancel();
                        }
                    }
                    if (url.equals(web.getUrl()) && "false".equals(result) && ("/login".equals(path) || "/app".equals(path) || "/".equals(path) || "/marketplace".equals(path))) { latestAlerts=null; Alerts.clear(MainActivity.this); }
                });
            }
            @Override public void onReceivedError(WebView view,WebResourceRequest request,WebResourceError error) {
                if (request.isForMainFrame()) showConnection("No pudimos cargar AgroLazo. Comprueba tu acceso a internet y vuelve a intentar. Si acababas de enviar una solicitud, revisa su estado antes de repetirla.");
            }
            @Override public void onReceivedHttpError(WebView view,WebResourceRequest request,WebResourceResponse response) {
                if (request.isForMainFrame() && response.getStatusCode() >= 500) showConnection("AgroLazo no está disponible en este momento. Vuelve a intentar en unos minutos. Si acababas de enviar una solicitud, revisa su estado antes de repetirla.");
            }
            @Override public void onReceivedSslError(WebView view,SslErrorHandler ssl,SslError error) {
                ssl.cancel(); showConnection("No pudimos verificar la conexión segura de AgroLazo. Inténtalo más tarde.");
            }
        });
        web.setWebChromeClient(new WebChromeClient() {
            @Override public void onProgressChanged(WebView view,int value) { progress.setProgress(value); }
            @Override public boolean onShowFileChooser(WebView view,ValueCallback<Uri[]> callback,FileChooserParams params) {
                if (!trusted(view.getUrl())) return false;
                if (fileCallback != null) fileCallback.onReceiveValue(null); fileCallback = callback;
                Intent pick = new Intent(Intent.ACTION_OPEN_DOCUMENT); pick.addCategory(Intent.CATEGORY_OPENABLE); pick.setType("*/*");
                pick.putExtra(Intent.EXTRA_MIME_TYPES,new String[]{"image/jpeg","image/png","image/webp","application/pdf"});
                pick.putExtra(Intent.EXTRA_ALLOW_MULTIPLE,params.getMode() == FileChooserParams.MODE_OPEN_MULTIPLE);
                try { startActivityForResult(pick,PICK_FILE); } catch (ActivityNotFoundException e) { fileCallback.onReceiveValue(null); fileCallback=null; }
                return true;
            }
            @Override public void onGeolocationPermissionsShowPrompt(String origin,GeolocationPermissions.Callback callback) {
                if (!trusted(origin) || !trusted(web.getUrl())) { callback.invoke(origin,false,false); return; }
                geoOrigin=origin; geoCallback=callback; requestLocationPermission();
            }
        });
        web.setDownloadListener((url,agent,disposition,mime,length) -> {
            if (!trusted(url)) return;
            pendingDownload=url; Intent save=new Intent(Intent.ACTION_CREATE_DOCUMENT); save.addCategory(Intent.CATEGORY_OPENABLE); save.setType(mime == null ? "application/octet-stream" : mime);
            save.putExtra(Intent.EXTRA_TITLE,URLUtil.guessFileName(url,disposition,mime));
            try { startActivityForResult(save,SAVE_FILE); } catch (ActivityNotFoundException e) { Toast.makeText(this,"No hay un selector de archivos disponible.",Toast.LENGTH_LONG).show(); }
        });
        root.addView(web,new LinearLayout.LayoutParams(-1,0,1));
        connectionPanel=new LinearLayout(this); connectionPanel.setOrientation(LinearLayout.VERTICAL); connectionPanel.setPadding(dp(28),dp(40),dp(28),dp(30)); connectionPanel.setBackgroundColor(Color.rgb(246,247,241)); connectionPanel.setVisibility(View.GONE);
        root.addView(connectionPanel,new LinearLayout.LayoutParams(-1,0,1)); setContentView(root);
        connectivity=(ConnectivityManager)getSystemService(CONNECTIVITY_SERVICE);
        networkCallback=new ConnectivityManager.NetworkCallback(){
            @Override public void onAvailable(Network network){handler.postDelayed(()->{if(pageFailed && !isFinishing())openHome();},800);}
        };
        connectivity.registerDefaultNetworkCallback(networkCallback);
        web.loadUrl(server+(getIntent().getBooleanExtra("open_notices",false) ? "/notifications" : "/app"));
    }

    private boolean trusted(String value) {
        try { Uri a=Uri.parse(server), b=Uri.parse(value == null ? "" : value); return a.getScheme().equals(b.getScheme()) && a.getHost().equalsIgnoreCase(b.getHost()) && port(a)==port(b) && b.getUserInfo()==null; } catch(Exception e) { return false; }
    }
    private int port(Uri uri) { return uri.getPort() == -1 ? ("https".equals(uri.getScheme()) ? 443 : 80) : uri.getPort(); }
    private String appVersion() {
        try { return getPackageManager().getPackageInfo(getPackageName(),0).versionName; }
        catch(PackageManager.NameNotFoundException e) { return "unknown"; }
    }
    private final class AutofillBridge {
        @JavascriptInterface public void remember(boolean enabled) {
            runOnUiThread(()->{
                if(!trusted(web.getUrl()) || !"/login".equals(Uri.parse(web.getUrl()).getPath()))return;
                getPreferences(MODE_PRIVATE).edit().putBoolean("remember_credentials",enabled).apply();
                AutofillManager autofill=getSystemService(AutofillManager.class);
                if(!enabled && autofill!=null)autofill.cancel();
            });
        }
        @JavascriptInterface public void request() {
            runOnUiThread(()->{
                if(!trusted(web.getUrl()) || !"/login".equals(Uri.parse(web.getUrl()).getPath()))return;
                AutofillManager autofill=getSystemService(AutofillManager.class);
                if(autofill!=null && autofill.isEnabled()) {
                    web.requestFocus(); autofill.requestAutofill(web);
                    Toast.makeText(MainActivity.this,"Elige una cuenta en las sugerencias del teclado. También puedes mantener pulsado el correo y elegir Autocompletar.",Toast.LENGTH_LONG).show();
                    return;
                }
                new AlertDialog.Builder(MainActivity.this).setTitle("Tus contraseñas guardadas")
                    .setMessage("Activa el autocompletado de Android y elige tu gestor de contraseñas. Después vuelve a AgroLazo y toca el correo. Al ingresar, acepta guardar el acceso si tu gestor lo ofrece.")
                    .setNegativeButton("Ahora no",null).setPositiveButton("Configurar autocompletado",(dialog,which)->{
                        try { startActivity(new Intent(android.provider.Settings.ACTION_REQUEST_SET_AUTOFILL_SERVICE).setData(Uri.parse("package:"+getPackageName()))); }
                        catch(ActivityNotFoundException e) { Toast.makeText(MainActivity.this,"Busca Autocompletar o Contraseñas en los ajustes de tu teléfono.",Toast.LENGTH_LONG).show(); }
                    }).show();
            });
        }
    }
    private int dp(int value) { return Math.round(value*getResources().getDisplayMetrics().density); }
    private Button button(String text,View.OnClickListener listener) { Button b=new Button(this); b.setText(text); b.setTextSize(12); b.setTextColor(Color.WHITE); b.setBackgroundTintList(android.content.res.ColorStateList.valueOf(Color.rgb(35,85,59))); b.setOnClickListener(listener); return b; }
    private void showConnection(String text) {
        pageFailed=true;
        web.setVisibility(View.GONE); progress.setVisibility(View.GONE); connectionPanel.removeAllViews(); connectionPanel.setVisibility(View.VISIBLE);
        ImageView mark=new ImageView(this); mark.setImageResource(R.drawable.ic_launcher); connectionPanel.addView(mark,new LinearLayout.LayoutParams(dp(64),dp(64)));
        TextView title=new TextView(this); title.setText("Pronto volvemos al campo"); title.setTextSize(28); title.setPadding(0,dp(24),0,0); title.setTextColor(Color.rgb(21,63,45)); connectionPanel.addView(title);
        TextView detail=new TextView(this); detail.setText(text); detail.setTextSize(16); detail.setTextColor(Color.rgb(69,88,75)); detail.setPadding(0,dp(24),0,dp(24)); connectionPanel.addView(detail);
        connectionPanel.addView(button("Volver a intentar",v->openHome()));
    }
    private void openHome() {
        pageFailed=false; connectionPanel.setVisibility(View.GONE); web.setVisibility(View.VISIBLE); web.loadUrl(server+"/app");
    }
    private void installLocationAdapter() {
        web.evaluateJavascript("(function(){if(window.__agroLocationInstalled)return;window.__agroLocationInstalled=true;let busy=false;window.__agroLocationResult=function(r){if(!window.__agroLocationPending)return;let p=window.__agroLocationPending;window.__agroLocationPending=null;busy=false;if(r.error){if(p.fail)p.fail({code:2,message:r.error});}else p.ok({coords:{latitude:r.lat,longitude:r.lng,accuracy:r.accuracy},timestamp:Date.now()});};navigator.geolocation.getCurrentPosition=function(ok,fail){if(busy){if(fail)fail({code:2,message:'Ya hay una solicitud de ubicación en curso.'});return;}busy=true;window.__agroLocationPending={ok:ok,fail:fail};AgroLazoLocation.request();};})();",null);
    }
    private final class LocationBridge {
        @JavascriptInterface public void request() { runOnUiThread(()->{if(trusted(web.getUrl())) {locationPending=true;requestLocationPermission();}}); }
    }
    private final class AlertsBridge {
        @JavascriptInterface public void enable() { runOnUiThread(()->{if(trusted(web.getUrl()))enableNotifications();}); }
        @JavascriptInterface public void clear() { runOnUiThread(()->{if(trusted(web.getUrl())){latestAlerts=null;Alerts.clear(MainActivity.this);}}); }
        @JavascriptInterface public void sync(String payload) {
            if(payload == null || payload.length()>100000)return;
            runOnUiThread(()->{
                if(!trusted(web.getUrl()))return;
                try {
                    JSONObject data=new JSONObject(payload); if(data.optLong("account",0)<=0)return;
                    latestAlerts=data; Alerts.accept(MainActivity.this,data,true);
                    if(!Alerts.prefs(MainActivity.this).getBoolean("asked",false))enableNotifications();
                } catch(Exception ignored){}
            });
        }
    }
    private void enableNotifications() {
        boolean asked=Alerts.prefs(this).getBoolean("asked",false);
        Alerts.prefs(this).edit().putBoolean("asked",true).apply();
        if(Build.VERSION.SDK_INT>=33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS)!=PackageManager.PERMISSION_GRANTED) {
            if(asked && !shouldShowRequestPermissionRationale(Manifest.permission.POST_NOTIFICATIONS)) {
                new AlertDialog.Builder(this).setTitle("Notificaciones de AgroLazo").setMessage("Puedes habilitar los avisos en los ajustes de notificaciones del teléfono. Tus novedades también quedan en Avisos y coordinación.")
                    .setNegativeButton("Ahora no",null).setPositiveButton("Abrir ajustes",(dialog,which)->startActivity(new Intent(android.provider.Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(android.provider.Settings.EXTRA_APP_PACKAGE,getPackageName()))).show();
            } else requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS},NOTIFICATION_PERMISSION);
            return;
        }
        Alerts.prefs(this).edit().putBoolean("enabled",true).apply();
        if(latestAlerts!=null)Alerts.accept(this,latestAlerts,true);
        Toast.makeText(this,Alerts.allowed(this)?"Avisos activados. Android puede demorar las consultas en segundo plano.":"Habilita los avisos de AgroLazo en los ajustes del teléfono.",Toast.LENGTH_LONG).show();
    }
    private void requestLocationPermission() {
        if (checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION)!=PackageManager.PERMISSION_GRANTED && checkSelfPermission(Manifest.permission.ACCESS_COARSE_LOCATION)!=PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.ACCESS_FINE_LOCATION,Manifest.permission.ACCESS_COARSE_LOCATION},LOCATION_PERMISSION);
        } else locationGranted(true);
    }
    @Override public void onRequestPermissionsResult(int request,String[] permissions,int[] results) {
        super.onRequestPermissionsResult(request,permissions,results);
        if(request==LOCATION_PERMISSION) {boolean allowed=false;for(int result:results)allowed|=result==PackageManager.PERMISSION_GRANTED;locationGranted(allowed);}
        if(request==NOTIFICATION_PERMISSION) {boolean allowed=results.length>0 && results[0]==PackageManager.PERMISSION_GRANTED;Alerts.prefs(this).edit().putBoolean("enabled",allowed).apply();if(allowed && latestAlerts!=null)Alerts.accept(this,latestAlerts,true);}
    }
    private void locationGranted(boolean granted) {
        if(geoCallback!=null){geoCallback.invoke(geoOrigin,granted,false);geoCallback=null;}
        if(!locationPending)return; locationPending=false;
        if(!granted){locationResult(null,"Activa el permiso de ubicación o marca el punto en el mapa.");return;}
        locations=(LocationManager)getSystemService(LOCATION_SERVICE);
        if(locationListener!=null)locations.removeUpdates(locationListener);
        locationListener=new LocationListener(){
            @Override public void onLocationChanged(Location location){locationResult(location,null);locations.removeUpdates(this);locationListener=null;}
            @Override public void onProviderEnabled(String provider){}
            @Override public void onProviderDisabled(String provider){}
            @Override public void onStatusChanged(String provider,int status,Bundle extras){}
        };
        boolean started=false;
        try {
            for(String provider:new String[]{LocationManager.GPS_PROVIDER,LocationManager.NETWORK_PROVIDER}) {
                if(LocationManager.GPS_PROVIDER.equals(provider) && checkSelfPermission(Manifest.permission.ACCESS_FINE_LOCATION)!=PackageManager.PERMISSION_GRANTED)continue;
                if(locations.isProviderEnabled(provider)){locations.requestLocationUpdates(provider,0,0,locationListener,Looper.getMainLooper());started=true;}
            }
        } catch(SecurityException e){locationResult(null,"Permiso de ubicación no disponible.");}
        if(!started){locationResult(null,"Activa la ubicación del teléfono o marca el punto en el mapa.");locationListener=null;return;}
        LocationListener pending=locationListener;
        handler.postDelayed(()->{if(locationListener==pending && pending!=null){locations.removeUpdates(pending);locationListener=null;locationResult(null,"No se obtuvo una ubicación reciente. Acércate a una ventana o marca el punto en el mapa.");}},25000);
    }
    private void locationResult(Location location,String error) {
        if(!trusted(web.getUrl()))return;
        try {JSONObject data=new JSONObject();if(location==null)data.put("error",error);else {data.put("lat",location.getLatitude());data.put("lng",location.getLongitude());data.put("accuracy",location.getAccuracy());}web.evaluateJavascript("window.__agroLocationResult && window.__agroLocationResult("+data.toString()+");",null);}catch(Exception ignored){}
    }
    @Override protected void onActivityResult(int request,int result,Intent data) {
        super.onActivityResult(request,result,data);
        if(request==PICK_FILE && fileCallback!=null){Uri[] values=null;if(result==RESULT_OK && data!=null){if(data.getClipData()!=null){int size=Math.min(data.getClipData().getItemCount(),10);values=new Uri[size];for(int i=0;i<size;i++)values[i]=data.getClipData().getItemAt(i).getUri();}else if(data.getData()!=null)values=new Uri[]{data.getData()};}fileCallback.onReceiveValue(values);fileCallback=null;}
        if(request==SAVE_FILE && result==RESULT_OK && data!=null && data.getData()!=null && pendingDownload!=null){download(pendingDownload,data.getData());pendingDownload=null;}
    }
    private void download(String url,Uri destination) {
        if(!trusted(url))return; String cookies=CookieManager.getInstance().getCookie(url);
        downloads.execute(()->{
            String result="Archivo guardado.";
            try {HttpURLConnection connection=(HttpURLConnection)new URL(url).openConnection();connection.setInstanceFollowRedirects(false);connection.setConnectTimeout(15000);connection.setReadTimeout(30000);if(cookies!=null)connection.setRequestProperty("Cookie",cookies);
                if(connection.getResponseCode()!=200)throw new IOException("Descarga no disponible");
                try(InputStream in=connection.getInputStream();OutputStream out=getContentResolver().openOutputStream(destination)){byte[] buffer=new byte[8192];int n;long total=0;while((n=in.read(buffer))!=-1){total+=n;if(total>50L*1024*1024)throw new IOException("Archivo demasiado grande");out.write(buffer,0,n);}}finally{connection.disconnect();}
            }catch(Exception e){result="No se pudo descargar el archivo. Revisa tu sesión y vuelve a intentar.";}
            final String message=result;runOnUiThread(()->Toast.makeText(this,message,Toast.LENGTH_LONG).show());
        });
    }
    @Override public void onBackPressed(){if(pageFailed)super.onBackPressed();else if(web.canGoBack())web.goBack();else super.onBackPressed();}
    @Override protected void onNewIntent(Intent intent){super.onNewIntent(intent);setIntent(intent);if(intent.getBooleanExtra("open_notices",false)){connectionPanel.setVisibility(View.GONE);web.setVisibility(View.VISIBLE);web.loadUrl(server+"/notifications");}}
    @Override protected void onResume(){super.onResume();if(latestAlerts!=null && Alerts.allowed(this)){Alerts.prefs(this).edit().putBoolean("enabled",true).apply();Alerts.accept(this,latestAlerts,true);}}
    @Override protected void onPause(){super.onPause();CookieManager.getInstance().flush();}
    @Override protected void onDestroy(){if(connectivity!=null && networkCallback!=null)connectivity.unregisterNetworkCallback(networkCallback);downloads.shutdownNow();if(locationListener!=null && locations!=null)locations.removeUpdates(locationListener);handler.removeCallbacksAndMessages(null);if(fileCallback!=null)fileCallback.onReceiveValue(null);web.removeJavascriptInterface("AgroLazoLocation");web.removeJavascriptInterface("AgroLazoAlerts");web.removeJavascriptInterface("AgroLazoAutofill");web.destroy();super.onDestroy();}
}

