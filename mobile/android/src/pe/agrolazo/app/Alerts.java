package pe.agrolazo.app;

import android.Manifest;
import android.app.*;
import android.app.job.*;
import android.content.*;
import android.content.pm.PackageManager;
import android.os.Build;
import org.json.JSONObject;

/** Device notifications use only the authenticated AgroLazo inbox. */
public final class Alerts {
    static final int JOB = 407, NOTICE = 408;
    static final String CHANNEL = "agrolazo_updates";
    static SharedPreferences prefs(Context context) { return context.getSharedPreferences("agrolazo_alerts", Context.MODE_PRIVATE); }
    static boolean allowed(Context context) {
        return (Build.VERSION.SDK_INT < 33 || context.checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED)
            && context.getSystemService(NotificationManager.class).areNotificationsEnabled();
    }
    static void channel(Context context) {
        NotificationChannel channel = new NotificationChannel(CHANNEL, "Ofertas, compras y coordinación", NotificationManager.IMPORTANCE_DEFAULT);
        channel.setDescription("Novedades de tus operaciones en AgroLazo");
        channel.setLockscreenVisibility(Notification.VISIBILITY_PRIVATE);
        context.getSystemService(NotificationManager.class).createNotificationChannel(channel);
    }
    static void clear(Context context) {
        prefs(context).edit().remove("account").remove("last_notice").apply();
        context.getSystemService(JobScheduler.class).cancel(JOB);
        context.getSystemService(NotificationManager.class).cancel(NOTICE);
    }
    static void schedule(Context context) {
        JobScheduler scheduler = context.getSystemService(JobScheduler.class);
        if (!allowed(context) || !prefs(context).getBoolean("enabled", false)) { scheduler.cancel(JOB); return; }
        if (scheduler.getPendingJob(JOB) == null) {
            scheduler.schedule(new JobInfo.Builder(JOB, new ComponentName(context, AlertJobService.class))
                .setRequiredNetworkType(JobInfo.NETWORK_TYPE_ANY).setPeriodic(15 * 60 * 1000L)
                .setPersisted(true).build());
        }
    }
    static void accept(Context context, JSONObject data, boolean foreground) {
        try {
            String account = String.valueOf(data.getLong("account"));
            String current = prefs(context).getString("account", "");
            if (!foreground && !account.equals(current)) return;
            if (!account.equals(current)) { clear(context); prefs(context).edit().putString("account", account).apply(); }
            if (!allowed(context) || !prefs(context).getBoolean("enabled", false)) return;
            channel(context); schedule(context);
            String latest = data.isNull("notice") ? "" : data.optLong("revision",0) + ":" + data.optString("notice", "");
            int unread = Math.max(0, data.optInt("unread", 0));
            if (unread == 0) context.getSystemService(NotificationManager.class).cancel(NOTICE);
            if (unread > 0 && !latest.isEmpty() && !latest.equals(prefs(context).getString("last_notice", ""))) {
                String body = "Abre AgroLazo para revisar tus novedades.";
                if (data.optJSONArray("notices") != null && data.getJSONArray("notices").length() > 0) body = data.getJSONArray("notices").getJSONObject(0).optString("body", body);
                if (body.length() > 500) body = body.substring(0, 500);
                Intent open = new Intent(context, MainActivity.class).putExtra("open_notices", true).addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_SINGLE_TOP);
                PendingIntent tap = PendingIntent.getActivity(context, NOTICE, open, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
                Notification publicNotice = new Notification.Builder(context, CHANNEL).setSmallIcon(R.drawable.ic_notification).setContentTitle("AgroLazo").setContentText("Tienes novedades en tu cuenta.").build();
                Notification notice = new Notification.Builder(context, CHANNEL).setSmallIcon(R.drawable.ic_notification)
                    .setContentTitle(unread == 1 ? "AgroLazo · Nuevo aviso" : "AgroLazo · " + unread + " avisos sin leer")
                    .setContentText(body).setStyle(new Notification.BigTextStyle().bigText(body))
                    .setContentIntent(tap).setAutoCancel(true).setNumber(unread).setCategory(Notification.CATEGORY_EVENT)
                    .setVisibility(Notification.VISIBILITY_PRIVATE).setPublicVersion(publicNotice).build();
                context.getSystemService(NotificationManager.class).notify(NOTICE, notice);
            }
            prefs(context).edit().putString("last_notice", latest).apply();
        } catch (Exception ignored) { /* Malformed responses never become device notifications. */ }
    }
}
