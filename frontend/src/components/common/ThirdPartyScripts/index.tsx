import ChatwootWidget from "../ChatwootWidget";
import {getConfig} from "../../../utilites/config.ts";

export const ThirdPartyScripts = () => {
    return (
        <>
            {getConfig('VITE_CHATWOOT_WEBSITE_TOKEN') && <ChatwootWidget/>}
        </>
    );
}
