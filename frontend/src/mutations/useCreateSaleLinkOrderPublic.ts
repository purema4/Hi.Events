import {useMutation} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {saleLinkClientPublic} from "../api/sale-link.client.ts";

export const useCreateSaleLinkOrderPublic = () => {
    return useMutation({
        mutationFn: ({eventId, token}: {
            eventId: IdParam,
            token: string,
        }) => saleLinkClientPublic.createOrder(eventId, token),
    });
}
