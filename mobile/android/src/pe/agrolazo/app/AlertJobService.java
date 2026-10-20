package pe.agrolazo.app;

import android.app.job.*;
import android.os.*;
import android.webkit.CookieManager;
import java.io.*;
import java.net.*;
import java.util.*;
import java.util.concurrent.*;
import org.json.JSONObject;

/** Android schedules background checks; battery policies can delay them. */
public final class AlertJobService extends JobService {
    private final ExecutorService executor = Executors.newSingleThreadExecutor();
    private final Handler main = new Handler(Looper.getMainLooper());
    private volatile boolean stopped;
    private volatile HttpURLConnection connection;
    @Override public boolean onStartJob(JobParameters parameters) {
        stopped = false;
        String origin = getString(R.string.default_server);
        String account = Alerts.prefs(this).getString("account", "");
        if (account.isEmpty() || !Alerts.allowed(this) || !Alerts.prefs(this).getBoolean("enabled", false)) return false;
        String cookies = CookieManager.getInstance().getCookie(origin);
        if (cookies == null || cookies.isEmpty() || !origin.startsWith("https://")) return false;
        executor.execute(() -> {
            boolean retry = false;
            try {
                connection = (HttpURLConnection)new URL(origin + "/coordination/updates").openConnection();
                connection.setInstanceFollowRedirects(false); connection.setConnectTimeout(10000); connection.setReadTimeout(10000);
                connection.setRequestProperty("Accept", "application/json"); connection.setRequestProperty("Cookie", cookies);
                connection.setRequestProperty("User-Agent", "AgroLazoAndroid/1.3.0");
                int status = connection.getResponseCode();
                if (status == 401 || status == 403) {
                    main.post(() -> { if (!stopped && account.equals(Alerts.prefs(this).getString("account", ""))) Alerts.clear(this); });
                } else if (status == 200 && connection.getContentType() != null && connection.getContentType().contains("application/json")) {
                    ByteArrayOutputStream bytes = new ByteArrayOutputStream();
                    try (InputStream input = connection.getInputStream()) { byte[] buffer = new byte[4096]; int count; while ((count = input.read(buffer)) != -1) { if (bytes.size() + count > 100000) throw new IOException("Response too large"); bytes.write(buffer, 0, count); } }
                    JSONObject data = new JSONObject(bytes.toString("UTF-8"));
                    List<String> newCookies = connection.getHeaderFields().get("Set-Cookie");
                    if (newCookies == null) newCookies = connection.getHeaderFields().get("set-cookie");
                    final List<String> refreshCookies = newCookies;
                    main.post(() -> {
                        if (stopped || !account.equals(Alerts.prefs(this).getString("account", "")) || !account.equals(String.valueOf(data.optLong("account", -1)))) return;
                        if (refreshCookies != null) for (String cookie : refreshCookies) CookieManager.getInstance().setCookie(origin, cookie);
                        CookieManager.getInstance().flush(); Alerts.accept(this, data, false);
                    });
                } else retry = status >= 500 || status == 429;
            } catch (Exception ignored) { retry = true; }
            finally {
                if (connection != null) connection.disconnect();
                final boolean reschedule = retry;
                main.post(() -> { if (!stopped) jobFinished(parameters, reschedule); });
            }
        });
        return true;
    }
    @Override public boolean onStopJob(JobParameters parameters) { stopped = true; if (connection != null) connection.disconnect(); return true; }
    @Override public void onDestroy() { stopped = true; if (connection != null) connection.disconnect(); executor.shutdownNow(); main.removeCallbacksAndMessages(null); super.onDestroy(); }
}

